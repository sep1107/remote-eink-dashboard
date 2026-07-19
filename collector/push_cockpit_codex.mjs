#!/usr/bin/env node
/**
 * Read Cockpit Tools' encrypted Codex quota cache and push display-only data.
 *
 * This intentionally never serializes, logs, or sends account tokens.  The
 * Cockpit detail file must be decrypted because its quota cache and OAuth
 * tokens are stored in the same encrypted envelope; only account.quota is
 * retained after decryption.  Plan type and anonymous quota-window metadata
 * are also retained solely for the dashboard's Plus/Team and 7D display.
 *
 * Required for a push:
 *   EINK_DASHBOARD_URL=https://dashboard.example.com
 *   EINK_DASHBOARD_INGEST_TOKEN=...
 * Optional:
 *   COCKPIT_TOOLS_DATA_DIR=/Users/.../.antigravity_cockpit
 *   --check   validates cache access without making a network request
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

function accountSummary(account) {
  const quota = account && typeof account.quota === "object" ? account.quota : {};
  const hourly = displayPercent(quota.hourly_percentage);
  const weekly = displayPercent(quota.weekly_percentage);
  const parts = [];
  if (hourly !== null) parts.push(`5h left ${100 - hourly}%`);
  if (weekly !== null) parts.push(`week left ${100 - weekly}%`);
  return parts.join(" · ") || "额度暂未刷新";
}

function planBadge(account) {
  const plan = String(account.plan_type || account.auth_file_plan_type || "").toLowerCase();
  if (/team|business|enterprise/.test(plan)) return "Team";
  if (/plus|pro/.test(plan)) return "Plus";
  return plan ? plan.slice(0, 20) : "—";
}

function quotaMetric(used, resetAt) {
  if (used === null) return null;
  const metric = { used };
  if (Number.isFinite(Number(resetAt)) && Number(resetAt) > 0) metric.reset_at = Math.round(Number(resetAt));
  return metric;
}

function resetWithin(resetAt, maxAheadSeconds) {
  const reset = Number(resetAt);
  const now = Math.floor(Date.now() / 1000);
  return Number.isFinite(reset) && reset > now && reset <= now + maxAheadSeconds;
}

function readQuotaAccounts() {
  const key = Buffer.from(readFileSync(join(dataDir, "secure-account-storage.key"), "utf8").trim(), "base64");
  if (key.length !== 32) throw new Error("Cockpit account cache key is invalid");
  const index = JSON.parse(readFileSync(join(dataDir, "codex_accounts.json"), "utf8"));
  const summaries = Array.isArray(index.accounts) ? index.accounts : [];

  return summaries.slice(0, 5).map((summary, position) => {
    const envelope = JSON.parse(readFileSync(join(dataDir, "codex_accounts", `${summary.id}.json`), "utf8"));
    const account = JSON.parse(decryptEnvelope(envelope, key).toString("utf8"));
    const quota = account && typeof account.quota === "object" ? account.quota : {};
    const entry = {
      name: `Codex ${position + 1}`,
      summary: accountSummary(account),
      plan: planBadge(account),
    };
    // Cockpit explicitly marks a temporarily unavailable 5H window as false.
    // In that case the dashboard leaves the column blank; otherwise it shows it.
    const hourlyReset = Number(quota.hourly_reset_time);
    const weeklyReset = Number(quota.weekly_reset_time);
    const hourlyIsFiveHour = resetWithin(hourlyReset, 5 * 60 * 60 + 5 * 60);
    const hourlyIsLegacySevenDay = !hourlyIsFiveHour
      && !resetWithin(weeklyReset, 7 * 24 * 60 * 60 + 5 * 60)
      && resetWithin(hourlyReset, 7 * 24 * 60 * 60 + 5 * 60);
    const fiveHour = quota.hourly_window_present === true && hourlyIsFiveHour
      ? quotaMetric(displayPercent(quota.hourly_percentage), hourlyReset)
      : null;
    const sevenDay = hourlyIsLegacySevenDay
      ? quotaMetric(displayPercent(quota.hourly_percentage), hourlyReset)
      : quotaMetric(displayPercent(quota.weekly_percentage), quota.weekly_reset_time);
    if (fiveHour) entry.five_hour = fiveHour;
    if (sevenDay) entry.seven_day = sevenDay;
    return entry;
  });
}

async function push(accounts) {
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
    body: JSON.stringify({ source: "codex", accounts }),
  });
  if (!response.ok) throw new Error(`Dashboard returned HTTP ${response.status}`);
}

const accounts = readQuotaAccounts();
if (!accounts.length) throw new Error("No Cockpit Codex accounts found");
if (process.argv.includes("--check")) {
  process.stdout.write(`Cockpit quota cache: ${accounts.length} account(s) read.\n`);
} else if (process.argv.includes("--json")) {
  process.stdout.write(JSON.stringify({ source: "codex", accounts }));
} else {
  await push(accounts);
  process.stdout.write(`Cockpit quota cache: ${accounts.length} account(s) pushed.\n`);
}
