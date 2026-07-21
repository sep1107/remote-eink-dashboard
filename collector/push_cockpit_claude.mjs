#!/usr/bin/env node
/**
 * Read Cockpit Tools' encrypted Claude account cache and push display-only quota.
 *
 * Mirrors push_cockpit_codex.mjs.  The Claude detail file must be decrypted
 * because its quota cache and OAuth credentials share one AES-256-GCM envelope;
 * only account.quota's percentage/reset fields and the anonymised plan label are
 * retained.  Credentials, cookies, email, and raw usage blobs are never
 * serialized, logged, or sent.
 *
 * The dashboard renders a single Claude slot, so this pushes one account: the
 * one Cockpit currently has selected, falling back to the most recently used
 * account that still carries a subscription (5H / 7D) window.  Gateway / API
 * accounts without those windows are skipped.
 *
 * Required for a push:
 *   EINK_DASHBOARD_URL=https://dashboard.example.com
 *   EINK_DASHBOARD_INGEST_TOKEN=...
 * Optional:
 *   COCKPIT_TOOLS_DATA_DIR=/Users/.../.antigravity_cockpit
 *   --check   validates cache access without making a network request
 *   --json    prints the exact payload without sending it
 */
import { readFileSync } from "node:fs";
import { createDecipheriv } from "node:crypto";
import { homedir } from "node:os";
import { join } from "node:path";

const dataDir = process.env.COCKPIT_TOOLS_DATA_DIR || join(homedir(), ".antigravity_cockpit");

function decryptEnvelope(envelope, key) {
  if (!envelope || envelope.algorithm !== "AES-256-GCM") {
    throw new Error("Cockpit account cache is not an AES-256-GCM envelope");
  }
  const ciphertextWithTag = Buffer.from(envelope.ciphertext, "base64");
  if (ciphertextWithTag.length < 17) {
    throw new Error("Cockpit account cache has an invalid ciphertext");
  }
  const decipher = createDecipheriv("aes-256-gcm", key, Buffer.from(envelope.nonce, "base64"));
  decipher.setAuthTag(ciphertextWithTag.subarray(-16));
  return Buffer.concat([
    decipher.update(ciphertextWithTag.subarray(0, -16)),
    decipher.final(),
  ]);
}

function displayPercent(value) {
  return Number.isFinite(value) ? Math.max(0, Math.min(100, Math.round(value))) : null;
}

function quotaMetric(used, resetAt) {
  if (used === null) return null;
  const metric = { used };
  const reset = Number(resetAt);
  if (Number.isFinite(reset) && reset > 0) metric.reset_at = Math.round(reset);
  return metric;
}

function planBadge(account) {
  const plan = String(account.plan_type || "").toLowerCase();
  if (/team|business|enterprise/.test(plan)) return "Team";
  if (/max/.test(plan)) return "Max";
  if (/pro|plus/.test(plan)) return "Pro";
  return plan ? plan.slice(0, 20) : "—";
}

function accountSummary(fiveHour, sevenDay) {
  const parts = [];
  if (fiveHour) parts.push(`5h left ${100 - fiveHour.used}%`);
  if (sevenDay) parts.push(`week left ${100 - sevenDay.used}%`);
  return parts.join(" · ") || "额度暂未刷新";
}

function readKey() {
  const key = Buffer.from(readFileSync(join(dataDir, "secure-account-storage.key"), "utf8").trim(), "base64");
  if (key.length !== 32) throw new Error("Cockpit account cache key is invalid");
  return key;
}

function selectedAccountId() {
  try {
    const current = JSON.parse(readFileSync(join(dataDir, "provider_current_accounts.json"), "utf8"));
    const id = current?.current_accounts?.claude_desktop_account;
    return typeof id === "string" ? id : null;
  } catch {
    return null;
  }
}

function buildClaudeAccount() {
  const key = readKey();
  const index = JSON.parse(readFileSync(join(dataDir, "claude_accounts.json"), "utf8"));
  const summaries = Array.isArray(index.accounts) ? index.accounts : [];
  const preferredId = selectedAccountId();

  const candidates = [];
  for (const summary of summaries.slice(0, 5)) {
    let account;
    try {
      const envelope = JSON.parse(readFileSync(join(dataDir, "claude_accounts", `${summary.id}.json`), "utf8"));
      account = JSON.parse(decryptEnvelope(envelope, key).toString("utf8"));
    } catch {
      continue;
    }
    const quota = account && typeof account.quota === "object" ? account.quota : {};
    const fiveHour = quotaMetric(displayPercent(quota.five_hour_percentage), quota.five_hour_reset_time);
    const sevenDay = quotaMetric(displayPercent(quota.seven_day_percentage), quota.seven_day_reset_time);
    // Fable window: Anthropic currently disables it for Pro plans, so Cockpit
    // omits it and this stays null (the dashboard then shows Fable at 0%).  Field
    // names are assumed to mirror the 5H/7D pair; forwarded automatically if the
    // quota is ever restored. Verify the field names then.
    const fable = quotaMetric(displayPercent(quota.fable_percentage), quota.fable_reset_time);
    // Gateway / API accounts carry no 5H or 7D subscription window; skip them.
    if (!fiveHour && !sevenDay) continue;
    candidates.push({
      id: account.id,
      plan: planBadge(account),
      lastUsed: Number(account.last_used) || 0,
      fiveHour,
      sevenDay,
      fable,
    });
  }
  if (!candidates.length) return null;

  const chosen =
    candidates.find((candidate) => candidate.id === preferredId) ||
    candidates.sort((a, b) => b.lastUsed - a.lastUsed)[0];

  const entry = {
    name: "Claude Code",
    summary: accountSummary(chosen.fiveHour, chosen.sevenDay),
    plan: chosen.plan,
  };
  if (chosen.fiveHour) entry.five_hour = chosen.fiveHour;
  if (chosen.sevenDay) entry.seven_day = chosen.sevenDay;
  if (chosen.fable) entry.fable = chosen.fable;
  return entry;
}

async function push(account) {
  const baseUrl = (process.env.EINK_DASHBOARD_URL || "").replace(/\/$/, "");
  const ingestToken = process.env.EINK_DASHBOARD_INGEST_TOKEN;
  if (!baseUrl || !ingestToken) {
    throw new Error("EINK_DASHBOARD_URL and EINK_DASHBOARD_INGEST_TOKEN are required");
  }
  const response = await fetch(`${baseUrl}/v1/ingest/quota`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${ingestToken}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ source: "claude", accounts: [account] }),
  });
  if (!response.ok) throw new Error(`Dashboard returned HTTP ${response.status}`);
}

const account = buildClaudeAccount();
if (!account) throw new Error("No Cockpit Claude account with a subscription window found");
if (process.argv.includes("--check")) {
  process.stdout.write(`Cockpit Claude quota: ${account.summary}\n`);
} else if (process.argv.includes("--json")) {
  process.stdout.write(JSON.stringify({ source: "claude", accounts: [account] }) + "\n");
} else {
  await push(account);
  process.stdout.write(`Cockpit Claude quota pushed: ${account.summary}\n`);
}
