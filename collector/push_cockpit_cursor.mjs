#!/usr/bin/env node
// Read only the selected Cursor account cache. Never serialize credentials or identity.
import { readFileSync } from "node:fs";
import { createDecipheriv } from "node:crypto";
import { execFileSync } from "node:child_process";
import { homedir } from "node:os";
import { join } from "node:path";
const dataDir = process.env.COCKPIT_TOOLS_DATA_DIR || join(homedir(), ".antigravity_cockpit");
const email = process.env.CURSOR_ACCOUNT_EMAIL;
if (!email) throw new Error("CURSOR_ACCOUNT_EMAIL is required; no fallback account is selected");
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


const rows = JSON.parse(execFileSync("sqlite3", ["-readonly", "-json", join(dataDir, "cursor_accounts.sqlite"),
  "SELECT account_id FROM summaries WHERE email='" + email.replaceAll("'", "''") + "'"], { encoding: "utf8" }) || "[]");
const ids = [...new Set(rows.map(row => row.account_id))];
if (ids.length !== 1 || !/^cursor_[a-f0-9]+$/.test(ids[0])) throw new Error("Selected Cursor account missing or ambiguous");
const key = Buffer.from(readFileSync(join(dataDir, "secure-account-storage.key"), "utf8").trim(), "base64");
if (key.length !== 32) throw new Error("Invalid Cockpit cache key");
const envelope = JSON.parse(readFileSync(join(dataDir, "cursor_accounts", ids[0] + ".json"), "utf8"));
const account = JSON.parse(decryptEnvelope(envelope, key));
if (account.email !== email) throw new Error("Cursor cache identity mismatch");
const usage = typeof account.cursor_usage_raw === "string" ? JSON.parse(account.cursor_usage_raw) : account.cursor_usage_raw;
const used = usage?.planUsage?.totalPercentUsed;
const reset = Number(usage?.planInfo?.includedUsageResetsAt || usage?.billingCycleEnd);
if (!Number.isFinite(used) || !Number.isFinite(reset) || reset <= 0) throw new Error("Cursor included usage is unavailable");
const percentage = Math.max(0, Math.min(100, Math.round(used)));
const period = usage?.planInfo?.includedUsagePeriod === "INCLUDED_USAGE_PERIOD_WEEKLY" ? "周额度" : "订阅额度";
const payload = { source: "cursor", accounts: [{ name: "Cursor", summary: `已用 ${percentage}%`,
  plan: period, included_usage: { used: percentage, reset_at: Math.floor(reset / 1000) } }] };
if (process.argv.includes("--check")) console.log(`Cursor ${period}: 已用 ${percentage}%`);
else if (process.argv.includes("--json")) console.log(JSON.stringify(payload));
else {
  const baseUrl = (process.env.EINK_DASHBOARD_URL || "").replace(/\/$/, "");
  const token = process.env.EINK_DASHBOARD_INGEST_TOKEN;
  if (!baseUrl || !token) throw new Error("Dashboard URL and ingest token are required");
  const response = await fetch(`${baseUrl}/v1/ingest/quota`, { method: "POST",
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" }, body: JSON.stringify(payload) });
  if (!response.ok) throw new Error(`Dashboard returned HTTP ${response.status}`);
  console.log("Cursor display quota pushed");
}
