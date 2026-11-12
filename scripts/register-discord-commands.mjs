import { resolve } from "node:path";
try {
  process.loadEnvFile(resolve(process.argv[2] || ".env"));
} catch {
  /* Environment variables may instead be supplied by the operator. */
}
const {
  DISCORD_CLIENT_ID: applicationId,
  DISCORD_GUILD_ID: guildId,
  DISCORD_BOT_TOKEN: token,
} = process.env;
if (!applicationId || !guildId || !token) {
  console.error(
    "Configure DISCORD_CLIENT_ID, DISCORD_GUILD_ID and DISCORD_BOT_TOKEN in the selected environment file first.",
  );
  process.exit(1);
}
const code = {
  type: 3,
  name: "code",
  description: "Verification code displayed in the ticket",
  required: true,
  min_length: 10,
  max_length: 10,
};
const reason = {
  type: 3,
  name: "reason",
  description: "Officer review note",
  required: false,
  max_length: 500,
};
const command = {
  name: "guildhall",
  description: "Officer character-verification decisions",
  default_member_permissions: "0",
  options: [
    {
      type: 1,
      name: "approve",
      description: "Approve ownership after reviewing proof",
      options: [code],
    },
    {
      type: 1,
      name: "deny",
      description: "Deny this character request",
      options: [code, reason],
    },
    {
      type: 1,
      name: "revoke",
      description: "Revoke a previously approved character",
      options: [code, reason],
    },
  ],
};
try {
  const response = await fetch(
    "https://discord.com/api/v10/applications/" +
      encodeURIComponent(applicationId) +
      "/guilds/" +
      encodeURIComponent(guildId) +
      "/commands",
    {
      method: "POST",
      headers: {
        Authorization: "Bot " + token,
        "Content-Type": "application/json",
      },
      body: JSON.stringify(command),
      redirect: "manual",
      signal: AbortSignal.timeout(10000),
    },
  );
  if (!response.ok) {
    console.error(
      "Discord command registration failed (HTTP " +
        response.status +
        "). Check application IDs, bot installation and permissions.",
    );
    process.exitCode = 1;
  } else
    console.log(
      "Registered /guildhall approve, deny and revoke in the configured server. Existing differently named commands were preserved. Enable the configured officer roles under Server Settings > Integrations > your app > Commands.",
    );
} catch {
  console.error("Unable to reach Discord. Check the network and try again.");
  process.exitCode = 1;
}
