# Public contact details and telephone ribbon

The live CMS still held `info@tdiibt.ac.ug` and `http://www.tdiibt.ac.ug`. Its two telephone rows used legacy `contact_phone_1` / `contact_phone_2` keys, but the shared public resolver reads `contact_phone`. This explains why the supplied telephone was absent from the ribbon.

At the owner's request, a precise institution-scoped PDO transaction updated `contact_email` to `info@piie.ac.ug`, `contact_website` to `https://piie.ac.ug` and inserted `contact_phone` as `+256 788 099193`. The existing five public settings were captured before the update in `output/public-contact-before.json`. No user/login address, SMTP credentials, payment settings or unrelated content was changed. Legacy telephone rows were preserved; the unverified other legacy number was not promoted into the public channels.

The website editor now uses the resolver's actual `contact_phone` key and the requested email/website defaults. PIIE seed defaults contain the same supplied contact details; no seeder is run on production. The tracked prototype's old email/website values are corrected too. A tracked-source search found no other exact old email/website values outside historical documentation.

The existing top utility ribbon now keeps telephone links visible on mobile. Email, website and brochure remain in the mobile menu; desktop displays all channels. The stylesheet URL is versioned so existing browsers receive the new mobile styling. Contacts remain CMS-driven and shared by the header, contact page, footer and mobile navigation.

Relevant local regression: 82 tests, 625 assertions passed. The reusable Chrome script accepts `--contacts` to verify named channels, absence of the old domain, telephone visibility in the top ribbon, layouts and navigation across 18 routes/states and seven viewport widths. It also retains the previously corrected short-page readiness condition.

Code CI/deployment and final live browser checks pending.
