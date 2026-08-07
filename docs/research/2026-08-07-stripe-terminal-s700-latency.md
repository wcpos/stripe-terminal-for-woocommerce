# Stripe Terminal S700 server-driven latency: what Stripe documents, what the community reports

**Date:** 2026-08-07
**Context:** This plugin uses the **server-driven** Terminal integration — PHP calls `POST /v1/terminal/readers/{id}/process_payment_intent`; there is no Terminal SDK connection between the browser/POS and the reader. A merchant reports a large delay between clicking "pay" and the Stripe Reader S700 showing the payment screen.
**Scope:** Stripe's own docs first, then community sources. Plugin source was not re-read for this document.

---

## Summary — most actionable findings

1. **The `process_payment_intent` HTTP 200 is *"an acknowledgement that the reader received the action"*.** So the reader's response time sits **inside** your PHP HTTP call, not after it. Timing that one call in isolation cleanly splits "reader/Stripe leg" from "our code" — this is the single highest-value diagnostic step available.
   ([collect-card-payment, server-driven](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven))

2. **Stripe publishes no latency SLA or expected-latency number for command dispatch, for either integration shape.** No figure exists in the docs, and the community has never published a measured `process_payment_intent` → prompt number either (see §4). Anyone quoting one is not citing a source.

3. **Docs and community point at the same shape from two directions: an idle reader is slow or unreachable on the first command.**
   - **Docs / Android Doze:** reader software **2.28.3.0 (2025-01-29)** — *"Improved device IoT connectivity when in Doze mode. This change removes the need to manually keep the screen active before initiating a transaction."* i.e. pre-2.28 the official workaround for this exact symptom was keeping the reader awake. **Check `device_sw_version` first**; current is `2.44.1.0`.
     ([S700/S710 changelog](https://docs.stripe.com/terminal/readers/stripe-reader-s700-s710#reader-software-changelog))
   - **Community / stale channel:** [stripe-terminal-ios#284](https://github.com/stripe/stripe-terminal-ios/issues/284) — after ~30–42 minutes idle, DNS resolution to `gator.stripe.com` starts failing and the reader **still reports "connected"** until a payment is attempted. Stripe's own engineer engaged and could not explain it.

4. **This repo's own issues are the strongest evidence available anywhere, and they name the error.** [wcpos#22](https://github.com/wcpos/stripe-terminal-for-woocommerce/issues/22) (S700, server-driven, 2026-03-02) relays Stripe support quoting dashboard error **ER400: "There was a timeout when sending this command to the reader."** [wcpos#27](https://github.com/wcpos/stripe-terminal-for-woocommerce/issues/27): *"First payment after a reader restart always works. Subsequent payments mostly fail… the amount never appears on the terminal display."* That signature — works once after restart, then commands stop reaching the reader — is the same shape as ios#284.

5. **`set_reader_display` before `process_payment_intent` is the documented way to prime the reader, and it enables pre-dip.** *"The `setReaderDisplay` method prepares the reader for pre-dipping. Your customer can present a payment method at any point after this method is called."* Pre-dip (US only) *"can help speed up transaction times."* Calling it when the cart is finalised takes reader wake time off the critical path.
   ([Display cart details, server-driven](https://docs.stripe.com/terminal/features/display?terminal-sdk-platform=server-driven))

6. **Cheap physical mitigations, both documented:** keep the reader **plugged in / docked** (the 1-hour screen timeout only applies on battery, and Stripe requires mains power for auto-updates anyway), and **run the on-device Diagnostics** (Settings → passcode `07139` → Diagnostics) which tests DNS resolution, Stripe connectivity, and Terminal events connectivity separately.

---

## 1. Expected command latency — how `process_payment_intent` reaches the reader

### What Stripe documents

The API call is fire-and-acknowledge, not fire-and-complete:

> "Processing the payment happens asynchronously. […] When you process a payment, Stripe immediately responds to the request with an HTTP `200` status code as an acknowledgement that the reader received the action. In most cases, the request returns a reader with an `in_progress` status. However, because processing occurs asynchronously, the action status might already reflect the final state (`succeeded` or `failed`) if the payment completes quickly. Simultaneously, the reader screen switches to a UI that prompts the customer to insert their card."
> — [collect-card-payment (server-driven)](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven)

Note the wording: the 200 is "an acknowledgement that **the reader received** the action". That implies Stripe waits on a reader-side ack before returning — confirmed by the existence of `terminal_reader_timeout`:

> "On rare occasions, a reader might fail to respond to an API request on time because of temporary networking issues. If this happens, you receive a `terminal_reader_timeout` error code […] On rare occasions, a `terminal_reader_timeout` error code is a false negative. In this scenario, you receive a `terminal_reader_timeout` error from the API as described above, but the reader has actually received the command successfully. False negatives happen when Stripe sends a message to the reader, but doesn't receive an acknowledgement back from the reader due to temporary networking failures."
> — [Reader timeout](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven#reader-timeout)

Request path: your server → Stripe API → (Stripe pushes to reader) → reader acks → Stripe returns 200. **A slow reader shows up as a slow PHP HTTP call.** Stripe's recommendation on that error is *"we recommend you retry the API request."*

This is exactly the error the merchant in [wcpos#22](https://github.com/wcpos/stripe-terminal-for-woocommerce/issues/22) hit, per Stripe support's own words: **ER400 — "There was a timeout when sending this command to the reader."**

### Transport / connection model

- **Not documented publicly.** Stripe never states whether the reader holds a websocket, MQTT session, or long-poll. The words "long-polling" and "persistent connection" appear nowhere in the Terminal docs.
- **Community evidence narrows it.** Logs in [stripe-terminal-ios#284](https://github.com/stripe/stripe-terminal-ios/issues/284) show traffic to `https://gator.stripe.com:443/protojsonservice/GatorService` — a **protobuf-over-JSON RPC endpoint over HTTPS**, not obviously MQTT or a websocket. (The `:4443 /protojsonservice/JackRabbitService` endpoint in the same logs is the SDK→reader LAN channel, irrelevant to server-driven.)
- Further indirect evidence: the reader changelog references **"Armada"** as a component that can "become unauthenticated" ([2.19.2.0](https://docs.stripe.com/terminal/readers/bbpos-wisepos-e#reader-software-changelog)); `armada.stripe.com` and `*.terminal-events.stripe.com` are both on the required Terminal allowlist ([Domains and IPs](https://docs.stripe.com/ips)); and on-device diagnostics test **"Stripe connectivity"** and **"Terminal events connectivity"** as two separate things.
- **Inference (unverified):** `*.terminal-events.stripe.com` is the reader's event/command channel; `armada.stripe.com` / `gator.stripe.com` are device management and RPC. Stripe does not confirm this. Both are worth checking on the merchant's firewall regardless.

### Documented factors that slow delivery of the command

| Factor | Source |
| --- | --- |
| Reader in Android **Doze** mode (fixed 2.28.3.0; before that the screen had to be kept awake) | [S700 changelog](https://docs.stripe.com/terminal/readers/stripe-reader-s700-s710#reader-software-changelog) |
| Poor network connectivity for server-driven integrations (improved 2.29.6.0) | same |
| Reader busy (`terminal_reader_busy`) — also returned "if it's busy performing updates, changing settings or if a card is inserted from the previous transaction" | [Reader busy](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven#reader-busy) |
| Reader offline (>2 min silence) → `terminal_reader_offline` (hard error, no queuing) | [Reader offline](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven#reader-offline) |
| Cellular fallback on S710: **"typically 15 seconds to 2 minutes"** during which server-driven online payments may fail | [Network transitions](https://docs.stripe.com/terminal/features/operate-offline/network-transitions) |
| Daily midnight restart for PCI compliance | [Smart readers](https://docs.stripe.com/terminal/smart-readers) |

**No figure is published for the normal case.**

For calibration on a *different* Stripe→reader path: fleet `Configuration` changes *"can take up to 10 minutes to reflect on the target readers"* ([Terminal configurations](https://docs.stripe.com/terminal/fleet/configurations-overview?dashboard-or-api=api)). That is settings propagation, not the payment path — but it shows Stripe does not treat all reader-bound pushes as instantaneous.

---

## 2. Sleep / cold start on the S700

### Screen timeout (documented, configurable)

> "The screen times out when the reader isn't connected to a power source. The default timeout of 1 hour improves battery performance. To update this value, go to the settings, select **Appearance**, then select a new screen timeout from the dropdown. The device screen turns on automatically after a device interaction occurs (such as touching the screen or picking up the device), or when the device enters the payments flow and a payment is initiated."
> — [Set up S700/S710 → Screen timeout](https://docs.stripe.com/terminal/payments/setup-reader/stripe-reader-s700-s710#screen-timeout)

Introduced in reader software **2.10.2.0 (2023-02-06)**: *"Devices now have a one hour screen timeout when the reader isn't connected to a power source."*

Two consequences:
- **On mains power / on the dock the screen does not time out.** Plugging in is the documented mitigation.
- "The device screen turns on … when the device enters the payments flow and a payment is initiated" describes precisely the moment the merchant is timing.

### Doze mode

The reader is Android-based ([S700 page](https://docs.stripe.com/terminal/readers/stripe-reader-s700-s710)), and Stripe explicitly acknowledges Android Doze:

> **2025-01-29 (version 2.28.3.0)**
> - "Improved device IoT connectivity when in Doze mode. This change removes the need to manually keep the screen active before initiating a transaction."
> - "Added log entries for when a device enters into and exits out of Doze mode."
> - "Added the ability to insert a card before the transaction is initiated. This requires version 2.28 and enabling a Stripe-controlled feature flag."
>
> — [S700/S710 changelog](https://docs.stripe.com/terminal/readers/stripe-reader-s700-s710#reader-software-changelog) (identical entry on [WisePOS E](https://docs.stripe.com/terminal/readers/bbpos-wisepos-e#reader-software-changelog) — same reader app)

"Removes the need to manually **keep the screen active before initiating a transaction**" is Stripe confirming that on pre-2.28 software the documented workaround for this exact symptom was keeping the reader awake. Android Doze defers background network access to maintenance windows; on an idle battery-powered device that easily costs seconds on the first command.

Also relevant, firmware `1.00.00.25` (2025-04-28): *"Fixed a bug in NFC card detection while in standby mode"* and *"Improved transaction performance for contact and contactless transactions."* — [S700 firmware versions](https://docs.stripe.com/terminal/readers/stripe-reader-s700-s710#firmware-versions)

> **Caveat:** Doze is Stripe's own documented explanation, but **no community source independently corroborates Doze as a cause** of dispatch latency (§4). The community evidence points instead at a stale reader↔Stripe channel. These are compatible — both produce "idle reader, first command slow or lost" — but don't over-claim Doze.

### Is there a documented way to keep the reader "warm"?

| Approach | Status |
| --- | --- |
| **Keep the reader on mains power / dock** | **Documented and recommended.** Prevents screen timeout; Stripe requires it for automatic updates: *"Even when not in use, leave Stripe Reader S700/S710 plugged in and powered on."* ([Set up S700](https://docs.stripe.com/terminal/payments/setup-reader/stripe-reader-s700-s710), [Smart readers](https://docs.stripe.com/terminal/smart-readers)) |
| **Raise the screen timeout** in Settings → Appearance | **Documented** as configurable. Dropdown values not listed in docs — check the device. |
| **Update reader software to ≥2.28.3.0** (current `2.44.1.0`) | **Documented** fix for the Doze latency path. |
| **`set_reader_display` before processing** | **Documented — the closest thing to an official "prime the reader" call.** *"call `setReaderDisplay` before processing the payment"*; *"The `setReaderDisplay` method prepares the reader for pre-dipping. Your customer can present a payment method at any point after this method is called. You can call `setReaderDisplay` multiple times to update the information displayed without impacting the pre-dipping process."* Stripe does **not** frame it as a wake/keep-warm mechanism — that part is inference — but it is a documented, supported call that puts the reader into the payment UI early. No webhook is sent for it; clearing it on server-driven requires `cancel_action`. ([Display cart details, server-driven](https://docs.stripe.com/terminal/features/display?terminal-sdk-platform=server-driven), [set_reader_display API](https://docs.stripe.com/api/terminal/readers/set_reader_display)) |
| **Periodic `set_reader_display` as an idle heartbeat** | **Undocumented workaround.** Nothing endorses polling the reader to keep it awake, and it occupies the reader's `action` slot. |
| **Kiosk mode / "disable sleep" toggle** | **Not found.** No documented kiosk mode or no-sleep setting in the stock reader app; screen timeout under Appearance is the only power-related setting exposed. Custom app control would require [Apps on Devices](https://docs.stripe.com/terminal/features/apps-on-devices/build), a different integration shape. |
| **Fleet-wide `Configuration` object** | **Doesn't cover power/sleep.** Covers splash screen, tipping, offline mode, reboot window — not screen timeout. Screen timeout is device-local only. ([Terminal configurations](https://docs.stripe.com/terminal/fleet/configurations-overview?dashboard-or-api=api)) |
| **Any ping/heartbeat endpoint** | **Does not exist** in the [Terminal Reader API](https://docs.stripe.com/api/terminal/readers). |

### Reader lifecycle / status reporting interval

- **Offline threshold: 2 minutes of silence.** *"A reader is considered offline if Stripe hasn't received any signal from that reader in the past 2 minutes."* ([Reader offline](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven#reader-offline))
- `last_seen_at` — *"Time the reader was last seen"* / "the timestamp for when the reader last connected to Stripe", a **millisecond** Unix timestamp. ([Reader object](https://docs.stripe.com/api/terminal/readers/object), [changelog 2025-10-29](https://docs.stripe.com/changelog/clover/2025-10-29/terminal-reader-last-seen))
- **The heartbeat interval itself is not published.** The 2-minute threshold implies a heartbeat well under 2 minutes, but Stripe states no value. **Unverified.**
- **`status: online` is known to lie.** ios#284 documents the reader reporting connected while the channel was dead, only surfacing the disconnect ~5 minutes after errors began. Don't gate UX on `status` alone.
- Dashboard reader events exist (Device powered on with restart reason, Network connected/disconnected with type and SSID, update events); *"the last 30 days of reader events"*, and *"Events can take several minutes to appear in the log."* ([Monitor readers](https://docs.stripe.com/terminal/fleet/monitor-readers))
- **Smart readers restart every day at midnight** for PCI compliance, in the assigned location's timezone. ([Smart readers](https://docs.stripe.com/terminal/smart-readers))

---

## 3. Network requirements that affect latency

All from [Terminal network requirements](https://docs.stripe.com/terminal/network-requirements) unless noted.

### Hard requirements for smart readers

- **IPv4 required.** *"IPv6-only networks aren't supported."* Dual-stack via DHCP is OK provided an IPv4 address is also assigned; *"Changing advanced settings such as static IP, router, subnet mask, and DNS aren't supported with IPv6."*
- Reader must be assigned a **private IP address**.
- **Wi-Fi:** must be password protected, **WPA/WPA2/WPA3-Personal or WPA2/WPA3 EAP-PEAP Enterprise**. *"Terminal readers don't support WiFi 6, also known as 802.11ax."* ← **worth checking: an ax-only SSID is a documented incompatibility.**
- **Ethernet:** networks must support **10/100** devices.
- **DHCP lease:** *"your DHCP server configuration needs to allow Terminal readers to retain the same IP address for at least an entire workday."*
  ← Corroborated by community: Stripe's engineer in ios#284 raised **reader IP address change on DHCP renewal** as a suspect for the stale-connection failure.
- **Session length:** *"If your network limits the duration of network sessions (including idle sessions), the minimum session length for Terminal readers must be at least an entire workday."*
  ← **The closest documented cause of "the first command after idle is slow."** A captive portal, guest-Wi-Fi idle timeout, or firewall idle-session reaper kills the reader's long-lived connection, forcing re-establishment on the next command.

### Wi-Fi vs Ethernet

- For **server-driven** specifically: *"If you use a server-driven integration, the reader communicates directly with Stripe over the internet and can use both WiFi and Ethernet without issue."* (The "don't use both at once" warning applies only to SDK integrations.)
- Network priority on S700/S710: **Ethernet > Wi-Fi**; on cellular-capable S710: **Ethernet > Wi-Fi > cellular**. Docking with an Ethernet cable switches the reader to Ethernet automatically. ([Set up S700/S710 → Network priority](https://docs.stripe.com/terminal/payments/setup-reader/stripe-reader-s700-s710#network-priority))
- Deployment checklist: *"If the WiFi signal is weak or unreliable, consider using Ethernet with the S700/S710 hub."* ([Deployment checklist](https://docs.stripe.com/terminal/references/checklist))
- **Community (anecdote, third-party POS vendors, not Stripe):** several integrator help centres recommend putting readers on **2.4 GHz rather than 5 GHz**, claiming 5 GHz drops more frequently under load. No Stripe source supports or contradicts this.

### DNS / firewall / domains

Terminal-specific FQDNs that must be allowlisted **in addition to** the standard Stripe domains ([Domains and IP addresses](https://docs.stripe.com/ips)):

```
api.emms.bbpos.com
armada.stripe.com
gator.stripe.com
stripe-point-of-sale-us-west-2.s3.us-west-2.amazonaws.com
*.terminal-events.stripe.com
```

NTP (device date sync):

```
time.android.com
time.cloudflare.com
2.android.pool.ntp.org
```

Partially-qualified (LAN discovery, **SDK integrations only** — not needed for server-driven):

```
*.[random-string].device.stripe-terminal-local-reader.net
```

- IP ranges for `files.stripe.com` / `armada.stripe.com` / `gator.stripe.com`: <https://stripe.com/files/ips/ips_armada_gator.txt>. *"Always use the DNS name api.stripe.com to contact our API. Never use an IP address."*
- **No port numbers are published** for the reader's outbound connections. Stripe only says *"Your firewall must allow outbound traffic to all required Stripe endpoints. Blocked endpoints are a common cause of reader connectivity failures that can be difficult to diagnose."* ([Deployment checklist](https://docs.stripe.com/terminal/references/checklist)) Community logs show `gator.stripe.com:443`. **Unverified** whether anything beyond 443 is used.
- **DNS is Stripe's own stated first suspect for reader command timeouts.** Stripe engineer `bric-stripe` in [stripe-terminal-ios#181](https://github.com/stripe/stripe-terminal-ios/issues/181): *"The most common cause for this is local DNS resolution failing"* and *"The most common issue we see is with an ISP's DNS provider not supporting local IP addresses."* (In that thread, switching Wi-Fi networks fixed it; overriding DNS did not — so DNS was the prior, not the confirmed cause.)
- **Proxies, VLANs, captive portals: not addressed anywhere in Stripe's docs.** The advanced-network-settings support article ([link](https://support.stripe.com/questions/bbpos-wisepos-e-stripe-reader-s700-s710-advanced-network-settings)) only explains *how* to reach the Advanced screen (Settings → passcode → Network → Connected Network → Advanced); no proxy/VLAN/captive-portal guidance exists. The idle-session requirement above is the only indirect coverage.
- Stripe explicitly disclaims network support: *"Because of the large variety of network configurations and infrastructure, Stripe can only help with basic network questions. The operation and troubleshooting of your network is your responsibility."*
- The `*.device.stripe-terminal-local-reader.net` DNS advice (Cloudflare/Google DNS, resolving `10-42-42-42.test...`) applies to the **point-of-sale device in SDK integrations** — not server-driven. Don't chase it here.

### Geographic distribution (community, Stripe-acknowledged)

[stripe-terminal-android#719](https://github.com/stripe/stripe-terminal-android/issues/719) (2026-06-11; Stripe employee `sjl-stripe` confirmed and opened an internal ticket) documents that Stripe's reader firmware assets are served from **S3 in US-Oregon with no regional distribution** — measured from the EU at ~142 ms RTT with 11% loss against ~1 ms for the reporter's own CloudFront-Frankfurt assets. If reader command routing shares that US-centric topology, non-US readers pay a transatlantic RTT per hop. **Unverified for the command path** — the issue concerns firmware download only.

### On-device diagnostics (fastest triage tool)

Swipe in from the left edge → **Settings** → passcode (default `07139`) → **Diagnostics**:

- **DNS resolution** — reader can resolve domain names
- **Stripe connectivity** — *"This test must pass for your reader to process payments and receive updates."*
- **Terminal events connectivity** — *"Tests the reader's connection to the Stripe event infrastructure."*
- **Wi-Fi information** — frequency and signal strength
- **Battery and hardware status** — incl. dock/hub connection

([S700/S710 diagnostics](https://docs.stripe.com/terminal/readers/stripe-reader-s700-s710#diagnostics))

Connectivity error codes worth knowing: `WR5` = *"Failure to connect to Stripe Services"*; `M1-A`/`M1-R` and `F-DOWNLOAD` = *"Add the host name to your allowlist"*; `AEU` = *"Update failed to fully download due to intermittent connectivity issues"*. ([Smart reader connectivity error codes](https://support.stripe.com/questions/smart-reader-connectivity-error-codes)) That article does **not** cover DNS, NTP, proxies, captive portals, MTU or VPN.

---

## 4. Known reports (community)

### Headline: nobody has published a measured number

Across an exhaustive sweep (GitHub issue search across `stripe/stripe-terminal-*` and third-party repos, the StackExchange API, Stripe support Q&A, dev blogs), **no third-party post, issue, or article quotes a measured latency for `process_payment_intent` → reader prompt.** This is a finding, not a gap in effort. Treat any "it should take N ms" claim as unsourced.

Explicit dead ends:
- **Reddit:** zero results. `WebFetch` refuses reddit.com; the JSON API is blocked server-side; six differently-phrased unfiltered searches surfaced no reddit.com URL at all. No r/stripe or r/woocommerce evidence exists in this report.
- **Stack Overflow:** effectively empty. Queried via the StackExchange API directly — the `stripe-terminal` tag contains **no** latency/slowness questions (top 95 by votes enumerated). Excerpt searches for "stripe terminal reader slow", "delay seconds", "wisepos slow", "process_payment_intent" returned one unrelated 2019 Chipper 2X pairing question.
- **Third-party server-driven wrapper repos:** `gh search` across all of GitHub found none discussing latency.
- `woocommerce/woocommerce-gateway-stripe`: no terminal latency issues.
- Stripe published a dev.to article *"Canceling reader actions and in-flight payments"* (`https://dev.to/stripe/canceling-reader-actions-and-in-flight-payments-hcd`) — **now 404s**, still in search indexes. Could not be retrieved.
- Stripe's developer forum at `insiders.stripe.dev` does not resolve.

### The strongest evidence is in this repo's own issues (first-party, S700, server-driven)

| Issue | Date | Report |
| --- | --- | --- |
| [wcpos#22](https://github.com/wcpos/stripe-terminal-for-woocommerce/issues/22) | 2026-03-02 | S700, server-driven, London merchant. Amount displays, customer taps, then *"a delayed 'processing' period"* → failure. Stripe dashboard shows **ER400**; **Stripe support's own words, relayed: "There was a timeout when sending this command to the reader."** Restarting the reader fixes exactly one transaction. Merchant confirms other devices on the network are fine. Stripe pointed back at the integration. |
| [wcpos#27](https://github.com/wcpos/stripe-terminal-for-woocommerce/issues/27) | 2026-03-04 | *"First payment after a reader restart always works. Subsequent payments mostly fail… the amount never appears on the terminal display"* and the POS times out. Workaround: restart the terminal before every transaction. |
| [wcpos#17](https://github.com/wcpos/stripe-terminal-for-woocommerce/issues/17) | 2026-02-28 | WisePOS E. Decline shown on the reader but POS stuck on "Payment in progress…" until **Check Payment Status** is clicked manually — the polling-gap symptom, distinct from dispatch latency. |

The #22/#27 signature — **works once after a restart, then commands stop reaching the reader** — matches ios#284 below. It is a stale/wedged reader↔Stripe channel, not a per-request slowness.

### Stale idle connection (best-documented community mechanism)

[**stripe/stripe-terminal-ios#284**](https://github.com/stripe/stripe-terminal-ios/issues/284) — 2024-02 — kiosk operator anecdote **with substantive Stripe engineer engagement (`bric-stripe`)**. SDK-driven, but the reader↔Stripe leg is shared.

After ~30–42 minutes idle:
- Repeated `NSURLErrorDomain -1003 "A server with the specified hostname could not be found"` against `https://gator.stripe.com:443/protojsonservice/GatorService` — **DNS resolution to Stripe's own Terminal backend host starts failing after idle**, with logs noting `Resolved 0 endpoints in 39ms using udp from cache`.
- Then TLS failures (`TLSV1_ALERT_INAPPROPRIATE_FALLBACK`) to the reader's LAN IP.
- **The connection was silently dead but reported healthy:** *"it shows 'connected' until the guest gets to checkout and starts the payment process. At this point, the reader will register the official disconnect and payment collection will fail."* An actual disconnect event fired ~5 min after errors began.

Stripe's engineer said holding the connection open *"should be fine"*, that the SDK heartbeats the reader and *"should indeed disconnect on its own when those start failing… not sure yet what's going on"*, and raised **reader IP address change (DHCP renewal)** as a suspect. Reporter's own hypothesis: *"IP lease renewals, access point reboots"*, and *"when it occurs it tends to not correct itself."*

**Reported fix that worked** (the only one in the corpus with a before/after): the operator rearchitected to *"only connect to the reader when the checkout flow is started, and disconnect when the session ends… Our error rates have dropped pretty significantly with this approach."* Note this **contradicts Stripe's advice in the same thread**. It's SDK-shaped and doesn't port directly to server-driven, but the transferable lesson is: **an idle connection is not trustworthy, and the reported state lies.**

### Smart-reader round-trip is measurably slow (adjacent path)

[**stripe/stripe-terminal-android#605**](https://github.com/stripe/stripe-terminal-android/issues/605) — 2025-07-30 — anecdote, unanswered by Stripe. Same app, same code path:

> "WisePad3 connection takes up to 10 seconds (worst case) and BBPOS a minute+ (always), however the implementation is common."

The internet/smart reader is consistently ~6x slower to establish a session than the Bluetooth reader. This is `connectReader`, not `process_payment_intent`, but it's the only published measurement of smart-reader round-trip latency anywhere.

### Stripe-side session path can wedge fleet-wide

[**stripe/stripe-terminal-android#740**](https://github.com/stripe/stripe-terminal-android/issues/740) — 2026-07-30 — anecdote, unusually well-instrumented, still open. Production incident: 822 "Timed out waiting for connection token" events in one hour vs a 14-day worst-hour baseline of 21 (~40x), across **67 devices, 60 locations, 43 unrelated merchants, 5 unrelated ISPs, 3 SDK versions simultaneously**. Their own token endpoint held p95 ~150 ms with zero errors; the SDK blocked the full 60 s without ever invoking the token provider.

Relevance: **Stripe's shared reader activation/session path can stall for tens of seconds across many merchants at once, with nothing wrong on the integrator's side or the network.** A merchant hitting this reports exactly "multi-second delays."

### Weaker / vendor-level fixes

- Switching Wi-Fi networks entirely (ios#181 — worked; manual DNS override did not).
- Restart reader / delete pairing and re-pair — [Dripos troubleshooting](https://support.dripos.com/troubleshoot/troubleshoot-readers) (third-party POS vendor, undated). Attributes "Slow/Delayed Response" purely to "slow network connection"; quantifies nothing.
- Retry the `process_payment_intent` request on timeout — Stripe support's official recommendation quoted in wcpos#22, matching the docs.

### Categories with NO community evidence found

Stated explicitly rather than speculated:

- **Android Doze / sleep mode on the reader** — not one community source discusses it or connects it to dispatch latency. Doze is a **docs-only** hypothesis.
- **Wi-Fi power-save (PSM/U-APSD) on the AP or reader** — nobody names it. Only adjacent: vendor 2.4-vs-5 GHz advice and the ios#284 reporter's AP-reboot speculation.
- **PaymentIntent creation latency as a contributor** — nothing.
- **Webhook vs polling, measured** — nothing quantitative.
- **`cancel_action` needed first as a *latency* cause** — no community measurement (Stripe's own article on it is 404).
- **How long the reader takes to re-establish its Stripe connection after idle** — no public source quantifies this. Circumstantial only: ios#284's ~30–42 min idle threshold, and wcpos#27's works-once-then-fails pattern.

---

## 5. Integration-shape factors

### Server-driven vs SDK — what Stripe actually claims

Stripe's claim for server-driven is **fewer failure modes**, not lower latency:

> "When using smart readers, we recommend a server-driven integration to minimize the number of potential network issues you might encounter."
> — [Network requirements](https://docs.stripe.com/terminal/network-requirements)

> "Server-driven allows you to avoid local network issues and DNS issues by using the Stripe API as the intermediary between your point of sale application and the reader."
> — [Terminal server-driven integration (support)](https://support.stripe.com/questions/terminal-server-driven-integration)

**Stripe nowhere claims server-driven is faster, and publishes no latency comparison.** Structurally it is the longer path: SDK-driven is POS → LAN → reader (one local hop); server-driven is POS → your server → Stripe → reader (WAN round trip each way). Higher click-to-prompt latency is expected; the magnitude is **unverified**.

Note the irony worth flagging to the merchant: server-driven's selling point is *avoiding local DNS issues*, yet the community's best-evidenced failure (ios#284) is **DNS resolution to `gator.stripe.com` failing from the reader** — a leg server-driven cannot remove.

Documented trade-offs for server-driven: no Bluetooth readers, and *"This integration shape doesn't support offline card payments."*

### Does creating the PaymentIntent ahead of time help?

- The flow is a strict two-step: **(1) create PaymentIntent → (2) `process_payment_intent`**. Nothing forbids doing step 1 earlier; nothing suggests it either.
- The PI must be in `requires_payment_method` when you call `process_payment_intent`, else `intent_invalid_state`. A pre-created PI stays valid as long as nothing has advanced it.
- Stripe does push PI re-use: *"Don't recreate a PaymentIntent if a card is declined. Instead, re-use the same PaymentIntent"*, and *"If you edit the PaymentIntent, you must call `process_payment_intent` to update the payment information on the reader."*
- **Practical read:** pre-creating the PI removes one Stripe API round trip (a few hundred ms) from the click-to-reader path. Real but small, and it is **inference, not documented guidance**. It does not touch the wake/stale-channel path, which is the bigger suspect. Watch the reconciliation risk Stripe flags: *"A user abandoning your application's checkout flow early can result in an un-captured PaymentIntent, which might appear to the cardholder as an unintended authorization."*
- **The bigger documented win in the same direction is `set_reader_display` + pre-dip** (§2): the customer can present their card *before* `process_payment_intent` is called, removing reader wake and card-presentment time from the perceived transaction. US only; requires reader ≥2.28 plus a Stripe-controlled feature flag.

### `process_payment_intent` when the reader is offline vs online

- **Offline → hard error, no queuing.** `terminal_reader_offline`: *"Reader is currently offline, please ensure the reader is powered on and connected to the internet before retrying your request."* No documented queue or buffer.
- **Busy → `terminal_reader_busy`**, including when the reader is *"busy performing updates, changing settings or if a card is inserted from the previous transaction."* Note: *"Payments that have not begun processing can be replaced with a new payment."*
- **Stuck `in_progress` → needs `cancel_action`.** *"A reader might be left with an action status of `in_progress` when this happens, and a cashier has to intervene by calling the `cancel_action` endpoint to reset the reader state."* Also: *"You can't cancel a reader action in the middle of a payment authorization […] Calling `cancel_action` during an authorization results in a `terminal_reader_busy` error."*
  → **Unconditional `cancel_action` before every payment is a latency anti-pattern**: it adds a full extra reader round trip to every transaction. Gate it on the reader's `action.status` actually being a stale `in_progress`. Caveat: `cancel_action` is also how you *clear* a `set_reader_display` cart on server-driven, so a display-priming flow needs care not to reintroduce the extra round trip.
- **Reader status freshness:** `status` flips to offline only after 2 minutes of silence, and community evidence shows it reporting healthy over a dead channel. `last_seen_at` (ms) is the finer signal but is still only as fresh as the last heartbeat.

### Verifying reader state — webhooks vs polling

> "For maximum resiliency, we recommend your application listens to webhooks […] We recommend having a dedicated webhook endpoint for only these events because they're high priority and in the critical payment path."
> — [collect-card-payment (server-driven)](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven)

Events: `terminal.reader.action_succeeded`, `terminal.reader.action_failed`, `terminal.reader.action_updated` (preview; `collect_payment_method` only). No webhook is sent for `set_reader_display` or `cancel_action`. Polling is a fallback: *"In case of webhook delivery issues, you can poll the Stripe API by adding a `check status` button."*

This affects perceived latency **after** the card is presented, not before — but a fixed polling interval pads observed end-to-end time. wcpos#17 is exactly this symptom.

### Other timing constants documented

- **Two-step (collect/confirm) flow only:** *"After payment method collection you must authorize the payment or cancel collection within 30 seconds."*
- **Manual capture:** *"You must manually capture PaymentIntents within two days or the authorization expires."*
- **Cellular fallback (S710 only):** *"typically 15 seconds to 2 minutes, depending on cellular signal strength."*
- **Fleet config propagation:** *"can take up to 10 minutes to reflect on the target readers."*

---

## What to tell the merchant to check

Ordered by likelihood × cheapness.

**Reader device**

1. **Is the reader plugged into power / on its dock?** On battery the screen sleeps after 1 hour by default and the device can enter Android Doze. Plug it in.
2. **What reader software version is it running?** Settings → passcode `07139` → Diagnostics, or read `device_sw_version` from the Reader object / Dashboard. **Anything below `2.28.3.0` is missing the Doze connectivity fix** that specifically removed the need to keep the screen awake before a transaction. Current is `2.44.1.0`. If stale: leave the reader on and plugged in overnight (updates install at midnight local) or reboot to force a check.
3. **Settings → Appearance → screen timeout** — raise it (only affects battery operation).
4. **Reboot the reader, then time payment #1 vs #2 vs #10.** This is the decisive test given wcpos#22/#27: *works once after restart, then degrades* means a stale channel, not per-request slowness. A uniform delay across all payments means network or integration.
5. **Note the idle gap before a slow payment.** ios#284's threshold was ~30–42 minutes. If slowness correlates with idle time, that's the stale-channel signature.

**Network**

6. **Run on-device Diagnostics** (Settings → `07139` → Diagnostics). All three must pass: **DNS resolution**, **Stripe connectivity**, **Terminal events connectivity**. Note Wi-Fi frequency and signal strength. Re-run it **immediately after a slow transaction** — a pass when idle and a fail after a stall is the smoking gun.
7. **Firewall allowlist** — outbound to `armada.stripe.com`, `gator.stripe.com`, `*.terminal-events.stripe.com`, `api.emms.bbpos.com`, `stripe-point-of-sale-us-west-2.s3.us-west-2.amazonaws.com`, plus NTP (`time.android.com`, `time.cloudflare.com`, `2.android.pool.ntp.org`).
8. **Idle-session / captive-portal timeouts.** Stripe requires *"the minimum session length for Terminal readers must be at least an entire workday."* Guest Wi-Fi with a 15-minute idle reaper, a captive portal re-auth, or a firewall dropping idle TCP sessions forces the reader to re-establish its connection on the first command after a quiet period. Move the reader to a non-guest SSID/VLAN with no idle timeout.
9. **DHCP lease** must let the reader keep its IP for a full workday — Stripe's own engineer flagged IP change on renewal as a suspect for exactly this failure. Consider a DHCP reservation for the reader.
10. **Wi-Fi 6 / 802.11ax is not supported.** If the AP runs an ax-only SSID, add a b/g/n/ac-compatible one.
11. **Try Ethernet** via the S700/S710 hub, or move the reader to a phone hotspot for one test. Stripe's own rule-out method: *"You can rule out the network as the cause of an issue by temporarily moving one or more of your Terminal readers […] to a different network and internet connection."*
12. **Dashboard → reader → reader events** — look for "Network disconnected" events lining up with slow transactions.

**Integration (for us, not the merchant)**

13. **Time the `process_payment_intent` HTTP call itself.** Stripe returns 200 only after the reader acknowledges, so a slow reader shows up as a slow PHP call. Log wall-clock duration of that single call and compare against total click-to-prompt. This is the highest-value instrumentation available and nothing else in this document substitutes for it.
14. **Log `error.code` on failure, and `last_seen_at` at the moment of the call.** Distinguish `terminal_reader_timeout` / `terminal_reader_offline` / `terminal_reader_busy` — they point at different causes, and per the docs `terminal_reader_timeout` can be a **false negative** (reader got the command anyway), so retry logic must not assume the command was lost.
15. **Call `set_reader_display` when the cart is finalised**, before the operator clicks pay — documented as the call that "prepares the reader for pre-dipping", putting the reader into the payment UI early.
16. **Don't call `cancel_action` unconditionally before each payment** — extra reader round trip. Gate it on `action.status == "in_progress"`.
17. **Pre-create the PaymentIntent** before the click. Small win; watch for orphaned uncaptured PIs on abandoned carts.
18. **Use `terminal.reader.action_succeeded` / `action_failed` webhooks** rather than (or alongside) polling; if polling, check the interval isn't padding measured time (cf. wcpos#17).

---

## Gaps / explicitly unverified

- No published latency figure or SLA for `process_payment_intent` → reader display, from Stripe or from anyone in the community.
- The reader→Stripe transport is not documented. Community logs show HTTPS RPC (`gator.stripe.com:443/protojsonservice/GatorService`), not obviously MQTT/websocket; the Armada + `terminal-events` split is inference from allowlist domains and diagnostics labels.
- The reader's heartbeat interval is not published; only the 2-minute offline threshold is.
- How long a reader takes to re-establish its Stripe connection after idle: no source quantifies it.
- No documented port list for reader outbound traffic (443 observed, nothing else confirmed).
- No Stripe guidance on proxies, VLANs, captive portals, or MTU for Terminal readers.
- **Doze is a docs-only hypothesis** — Stripe names it, no community source corroborates it as a dispatch-latency cause.
- **Wi-Fi power-save on APs is unsupported by any source** found — do not present it to the merchant as a known cause.
- No documented keep-warm mechanism, ping endpoint, or kiosk/no-sleep toggle. `set_reader_display` is documented as a pre-payment "prepare the reader" call, but Stripe never frames it as a wake or heartbeat mechanism — using it that way is our inference.
- The selectable screen-timeout values in Settings → Appearance are not documented.
- Whether the pre-dip feature flag ("requires version 2.28 and enabling a Stripe-controlled feature flag") is on for a given account is not documented — confirm with Stripe support.
- Whether Stripe's reader command routing shares the US-Oregon topology documented for firmware assets (android#719) is unconfirmed.

---

## set_reader_display: busy-reader and zero-total behavior (follow-up)

**Question asked:** we plan to call `POST /v1/terminal/readers/{id}/set_reader_display` as a periodic keep-warm ping with a zero-total cart. (1) What happens if it lands while a payment is `in_progress`? (2) Is a zero total accepted?

**Bottom line: do not fire a blind periodic ping.** Neither question has a documented answer, and the structural evidence says a successful `set_reader_display` would *overwrite* the reader's single `action` slot. The residual risk is real and is not excluded by any source.

### Verified (primary sources)

**The Reader has exactly one `action` slot, and both endpoints write to it.** From Stripe's own OpenAPI spec, `terminal_reader_reader_resource_reader_action`:

- `action.type` is a single enum: `collect_inputs`, `collect_payment_method`, `confirm_payment_intent`, `print_content`, `process_payment_intent`, `process_setup_intent`, `refund_payment`, **`set_reader_display`**
- `action.status` is a single enum: `failed`, `in_progress`, `succeeded`
- `required: ["status", "type"]`; the parent field is documented as *"The most recent action performed by the reader."*

(`stripe/openapi` → `openapi/spec3.json`, `components.schemas.terminal_reader_reader_resource_reader_action`; mirrored in the [Reader object reference](https://docs.stripe.com/api/terminal/readers/object).)

→ **There is no separate "display" channel.** A `set_reader_display` that succeeds mid-payment necessarily replaces the `process_payment_intent` action, which would destroy the state your integration polls and make the reader's action webhooks ambiguous.

**Non-payment reader endpoints *are* busy-rejected during a payment.** Documented precedent for `cancel_action`, the closest analogue to `set_reader_display` (both non-payment reader actions):

> "You can't cancel a reader action in the middle of a payment authorization. If a customer has already presented their card to pay on the reader, you must wait for processing to complete. […] Calling `cancel_action` during an authorization results in a `terminal_reader_busy` error."
> — [Payment cancellation](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven#payment-cancellation)

**`terminal_reader_busy` is worded generically, not payment-specifically:**

> `terminal_reader_busy` — "Reader is currently busy processing another request."
> — [Error codes](https://docs.stripe.com/error-codes)

> "A reader also rejects an API request if it's busy performing updates, changing settings or if a card is inserted from the previous transaction."
> — [Reader busy](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven#reader-busy)

**A reader displaying a cart is itself treated as busy / in use — confirmed by Stripe, and the docs that said otherwise were wrong.** Stripe collaborator `bric-stripe` on [stripe-terminal-android#337](https://github.com/stripe/stripe-terminal-android/issues/337) (2024-02-05):

> "This is working as intended and **the docs were incorrect. Setting the reader display is treated as 'busy'** so requires the `failIfInUse` flag to be false."

And the reason, from the same engineer on [stripe-terminal-ios#233](https://github.com/stripe/stripe-terminal-ios/issues/233) (2023-06-07):

> "if the reader is in a region that supports 'Quick Chip' **the reader can accept payment during while its displaying cart information during setReaderDisplay**. So for that, and then for consistency, it intentionally treats that as 'in use' for the fail if in use check."

The [connect-reader docs](https://docs.stripe.com/terminal/payments/connect-reader?terminal-sdk-platform=android&reader-type=internet) have since been corrected — "idle" is now defined as *"displaying the welcome screen before calling `collectPaymentMethod`"*, with the old "or displaying cart details" clause removed.

→ **A keep-warm ping does not leave the reader idle. It puts the reader into a state Stripe classifies as in use**, and in Quick Chip regions it arms the reader to accept a card.

**`set_reader_display` does not auto-clear on server-driven.**

> "To clear reader display on the server-driven integration, call the `cancel_action` endpoint."
> — [Display cart details, server-driven](https://docs.stripe.com/terminal/features/display?terminal-sdk-platform=server-driven)

**Zero-total: the schema imposes no minimum.** From `spec3.json`, the `set_reader_display` request body:

```json
"cart": {
  "properties": {
    "currency":   {"format": "currency", "type": "string"},
    "line_items": {"type": "array", "items": {"properties": {
        "amount": {"type": "integer"},
        "description": {"maxLength": 5000, "type": "string"},
        "quantity": {"type": "integer"}},
      "required": ["amount", "description", "quantity"]}},
    "tax":   {"type": "integer"},
    "total": {"type": "integer"}
  },
  "required": ["currency", "line_items", "total"]
}
```

No `minimum`, no `exclusiveMinimum`, no `minItems`. Top-level `required: ["type"]`; `type` is enum `["cart"]`; `cart` itself is optional. The stripe-php generated PHPDoc agrees: `array{cart?: array{currency: string, line_items: array{amount: int, description: string, quantity: int}[], tax?: int, total: int}, expand?: string[], type: string}` (`lib/Service/Terminal/ReaderService.php`).

**The prose contrast is the real signal.** Stripe marks positivity in descriptions where it means it, and does *not* for the cart fields:

| Field | Documented description |
| --- | --- |
| `cart.total` | "Total balance of cart due in the smallest currency unit." |
| `cart.line_items.amount` | "The price of the item in the smallest currency unit." |
| `cart.tax` | "The amount of tax in the smallest currency unit." |
| `process_config.tipping.amount_eligible` (on `process_payment_intent`) | "**Must be a positive integer** in the smallest currency unit…" |
| `payment_intents.amount` | "**A positive integer** representing how much to charge…" |

([set_reader_display](https://docs.stripe.com/api/terminal/readers/set_reader_display), [process_payment_intent](https://docs.stripe.com/api/terminal/readers/process_payment_intent))

**`set_reader_display` can succeed at the API and fail silently at the device.** [stripe-node#1692](https://github.com/stripe/stripe-node/issues/1692) (2023-02-24, anecdote, closed as "ask support"): sending float values for `tax` or `line_items[].amount` produced **HTTP success on every request** while the reader *"shows the default background image"*:

> "I know that you're not supposed to send float values, however, I'd expect an error here and not just silently fail. All the requests in the dev tools also succeed, however, the just terminal shows the default background image."

→ **A 200 from `set_reader_display` is not evidence the reader rendered anything.** A malformed or degenerate cart can blank the reader with no error surfaced anywhere.

**Not a blocker, but worth knowing:** [stripe-terminal-react-native#1005](https://github.com/stripe/stripe-terminal-react-native/issues/1005) is titled "setReaderDisplay not supported on S700", which is misleading. Stripe collaborator `ugochukwu-stripe` (2025-08-01): *"We intentionally do not support {{SetReaderDisplay/ClearReaderDisplay}} for **handoff mode/apps on devices**, since this integration shape has an application running on the reader and can build a more customizable Cart UI."* That restriction is specific to handoff / Apps on Devices. **Server-driven `set_reader_display` on S700 is supported** — it is the documented flow on the [display page](https://docs.stripe.com/terminal/features/display?terminal-sdk-platform=server-driven).

### Unverified

- **What `set_reader_display` actually returns during an `in_progress` `process_payment_intent` on a server-driven integration.** No Stripe doc states it. No community report exists — `gh search issues` for `setReaderDisplay` / `set_reader_display` / `terminal_reader_busy` across all of GitHub, and a StackExchange API query for `setReaderDisplay` (1 unrelated hit), turned up nothing on this interaction. The three plausible outcomes — rejected with `terminal_reader_busy`, silently replacing the payment action, or accepted-and-ignored — are all consistent with the sources; none is excluded.
- **The counter-evidence you must weigh:** the docs say *"Payments that have not begun processing can be replaced with a new payment."* ([Reader busy](https://docs.stripe.com/terminal/payments/collect-card-payment?terminal-sdk-platform=server-driven#reader-busy)). A `process_payment_intent` that is `in_progress` with no card yet presented has *not* begun processing — so a replacement window demonstrably exists in exactly the state a keep-warm ping is most likely to hit. Whether `set_reader_display` counts as a replacing action there is undocumented. **This is the specific risk that makes a blind ping unsafe.**
- **HTTP status code for `terminal_reader_busy`.** Not published. Its `type` is `invalid_request_error`, which Stripe normally maps to **400**; the docs example shows only the error body, no status line. 409 is not ruled out.
- **Whether a zero total is accepted in practice**, and what the reader renders if it is. No community report of a zero-amount cart exists, accepted or rejected. Given #1692, "API accepts it and the reader shows the splash screen" is a live possibility — which would both defeat the keep-warm purpose and blank the display.
- **Error code if a zero/degenerate cart is rejected.** No `terminal_*` code covers amount validation; it would most likely surface as `parameter_invalid_integer` or a bare `invalid_request_error`. Unconfirmed.
- **How long a `set_reader_display` cart stays on screen.** No TTL is documented anywhere. The only documented way to clear it on server-driven is `cancel_action`, which implies it does *not* expire on its own. On the SDK side, `bric-stripe` agreed clearing on disconnect "makes sense" and noted *"the JS SDK is already doing this"* ([ios#233](https://github.com/stripe/stripe-terminal-ios/issues/233)) — SDK-specific, no bearing on server-driven. The 1-hour battery screen timeout blanks the *display* but says nothing about the *action*.

### Recommendation

1. **Don't ping on a timer.** Every source points the same way: the ping writes to the same single `action` slot the payment uses, and Stripe treats a displayed cart as "in use". A timer will eventually fire inside a payment window.
2. **If you keep any priming at all, make it event-driven** — fire `set_reader_display` once when the cart is finalised, which is what Stripe documents (*"call `setReaderDisplay` before processing the payment"*) and which also buys pre-dip in the US. That is the supported shape and it addresses the same latency goal.
3. **Gate on reader state regardless:** read the Reader and skip unless `action` is null or `action.status` is `succeeded`/`failed`. Note this is TOCTOU-racy — it narrows the window, it does not close it.
4. **Send a real cart, not a zero one.** Even if zero is accepted, #1692 shows the reader can render nothing on a cart the API accepted, and a blank reader is worse than a sleepy one.
5. **Before shipping any of this, test empirically against a sandbox reader** — call `set_reader_display` while a `process_payment_intent` is `in_progress` and record the HTTP status, `error.code`, and what the reader screen does. That single experiment resolves everything marked unverified above, and nothing in the public record substitutes for it.

## Simulated-reader experiment results (2026-08-07)

The decisive experiment recommended above was run against Stripe's test-mode
API with a fresh simulated WisePOS E reader (`registration_code=simulated-wpe`,
US account). Raw sequence and observed results:

1. **Zero-total cart on idle reader** — `POST .../set_reader_display` with
   `cart[total]=0`, one zero-amount line item: **HTTP 200, accepted**. The
   reader's `action` becomes `{type: set_reader_display, status: in_progress}`
   and **stays `in_progress` indefinitely** (a display action never reaches a
   terminal status on its own). Implication: any gate written as "skip if
   action.status == in_progress" blocks all warms after the first; gates must
   discriminate on `action.type`.
2. **Display during in-progress payment (the safety question)** — with a
   `process_payment_intent` action `in_progress` on the reader (PI dispatched,
   card not yet presented): `set_reader_display` returned **HTTP 200** and
   **replaced the payment action**. Reader `action` became
   `set_reader_display in_progress` with no payment intent; the PaymentIntent
   reverted to `requires_payment_method`; a subsequent
   `present_payment_method` test-helper call did not complete the payment.
   **No `terminal_reader_busy` rejection occurred. A warming ping fired
   mid-payment kills the payment silently.** This confirms the worst of the
   three outcomes left open by the public record (simulated reader; a physical
   S700 mid-card-read was not tested and could differ).
3. **Payment after display** — dispatching `process_payment_intent` while the
   reader showed a previously set cart succeeded and cleanly replaced the
   display action (the documented pre-dip ordering). `cancel_action` also
   clears a display action.

Consequences applied in this plugin: keep-warm ships **disabled by default**
(`stwc_enable_reader_keep_warm` filter, default `false`); when enabled, warms
are refused while a non-display action is `in_progress` **and** for 120s after
any payment dispatch (`stwc_payment_dispatch_at` option stamped in the payment
AJAX path). These guards narrow the TOCTOU race; they cannot close it.
