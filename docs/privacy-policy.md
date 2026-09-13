# Privacy Policy — SEPUM Event Management System

**Effective date:** [EFFECTIVE_DATE]
**Last updated:** [LAST_UPDATED_DATE]

This Privacy Policy explains how **[LEGAL_ENTITY_NAME]** ("we", "us", "our") collects, uses, stores, shares, and protects personal information when you use the **SEPUM Event Management System** ("AMS", the "Service") — a web and mobile application used by churches, missions, unions, and other organizations to register attendees for events and record session attendance.

If you do not agree with this Policy, please do not use the Service.

**Contact:** [PRIVACY_CONTACT_EMAIL] (currently `sepum.ems@gmail.com`)
**Postal address:** [ORGANIZATION_POSTAL_ADDRESS]
**Data Protection Officer / Privacy Contact:** [DPO_NAME_OR_ROLE]

---

## 1. Who controls your data

The Service is operated on behalf of participating organizations (the "Organization" that invited or registered you). In most jurisdictions:

- The **Organization** that registered you is the **data controller** — it decides why your data is collected.
- **[LEGAL_ENTITY_NAME]** operates the platform and acts as a **data processor / service provider** on the Organization's instructions.

If you are unsure which Organization holds your records, contact us and we will direct your request.

---

## 2. Information we collect

### 2.1 Information you or your Organization provides

| Category    | Data                                                                                                                       | Purpose                                                                         |
| ----------- | -------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Identity    | First name, middle name, last name                                                                                         | Identify you on rosters, ID cards, and attendance reports                       |
| Contact     | Email address, mobile number                                                                                               | Account invitations, registration confirmations, password resets, event notices |
| Account     | Password (stored only as a salted hash), role (Super Admin / Org Admin / Checker / Attendee), email verification timestamp | Authentication and access control                                               |
| Affiliation | Organization, union, mission, church                                                                                       | Grouping, eligibility, and aggregate reporting                                  |
| Photo       | Profile / ID-card photograph (optional)                                                                                    | Printed or digital event identification cards                                   |
| Notes       | Free-text remarks entered by event staff                                                                                   | Event administration                                                            |

### 2.2 Information created by using the Service

- **Event registrations** — which events you are registered for, who registered you, and when.
- **Attendance records** — check-in and check-out timestamps per session, and the method used (QR scan or manual entry).
- **QR / badge tokens** — a random, opaque token per registration. It is **not** derived from your name, email, or any identifier, and is never reused.
- **Generated documents** — attendance ID cards (PDF and image renderings) containing your name and QR code.
- **Audit logs** — the action performed, the acting user, affected record, a summary of the change, the **IP address**, and the **browser/device user-agent string**. Audit logs are immutable and exist for security, accountability, and dispute resolution.

### 2.3 Information from single sign-on (SSO) providers

If you choose to sign in with Google, Microsoft, or Facebook, we receive a limited profile from that provider:

- Your **email address** (required — sign-in fails without it)
- Your **display name**
- A **stable provider user ID** (stored to link your future logins to the same account)

We do **not** receive or request your social-account password. We request only the minimum scopes needed for sign-in (typically `openid`, `email`, `profile` or the provider's equivalent, e.g. Facebook `email` and `public_profile`). We do **not** request or access your contacts, friend list, posts, photos, calendar, drive files, or any other content.

We store these fields in a linked-identity record: the provider name, the provider user ID, and the email address from the provider.

### 2.4 Information we do **not** collect

We do not collect payment card data, government ID numbers, precise geolocation, biometric identifiers (face/fingerprint templates), health data, or advertising identifiers. We do not use the Service to build advertising profiles.

---

## 3. Google user data — Limited Use disclosure

The Service's use and transfer of information received from Google APIs adheres to the [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy), including the **Limited Use** requirements.

Specifically:

1. We use Google user data **only** to authenticate you and to create or link your Service account.
2. We do **not** transfer Google user data to third parties except as necessary to provide or improve the Service, to comply with applicable law, or as part of a merger/acquisition with equivalent protections.
3. We do **not** use Google user data for advertising, and we do **not** sell it.
4. We do **not** allow humans to read Google user data unless (a) you give explicit consent for a specific case, (b) it is necessary for security purposes such as investigating abuse, (c) it is required to comply with applicable law, or (d) the data is aggregated and anonymized for internal operations.

**Revoking Google access:** you may revoke the Service's access at any time at [myaccount.google.com/permissions](https://myaccount.google.com/permissions). Revoking access disables Google sign-in; it does not by itself delete your account data — use the deletion process in Section 9.

---

## 4. Meta (Facebook) Platform data

When you sign in with Facebook, we receive only your name, email address, and Facebook user ID, as described in Section 2.3.

- We use this data **solely** to create, identify, and authenticate your Service account.
- We do **not** sell, rent, or license Facebook Platform Data.
- We do **not** use Facebook Platform Data for advertising, ad targeting, audience building, or to make eligibility decisions about credit, insurance, employment, education, or housing.
- We do **not** place Facebook Platform Data into a search engine or directory, or transfer it to a data broker.
- We retain Facebook Platform Data only for as long as needed to operate your account, and delete it upon request (Section 9).

**Revoking Facebook access:** visit **Settings → Apps and Websites** on Facebook and remove "SEPUM Event Management System".

**Data deletion request (Meta requirement):** see **Section 9 — Data deletion instructions**, or go directly to **[DATA_DELETION_URL]**.

---

## 5. Microsoft account data

When you sign in with Microsoft, we receive your name, email address, and Microsoft account/tenant user ID, used solely for authentication and account linking, on the same terms described above.

---

## 6. How we use your information

We process personal data to:

1. Create and secure your account, verify your email, and authenticate sign-ins.
2. Register you for events and issue QR codes and identification cards.
3. Record and display session attendance (check-in/check-out).
4. Send transactional email: account invitations, registration confirmations, email verification, and password resets. We do not send marketing email.
5. Produce attendance reports and exports (Excel/PDF) for authorized Organization staff.
6. Detect and prevent duplicate records, fraud, and abuse.
7. Maintain audit logs for security and accountability.
8. Comply with legal obligations.

**Legal bases (GDPR, where applicable):** performance of a contract (Art. 6(1)(b)), legitimate interests in operating and securing event administration (Art. 6(1)(f)), consent where required (Art. 6(1)(a)), and legal obligation (Art. 6(1)(c)).

**Automated decision-making:** we do not carry out automated decision-making or profiling that produces legal or similarly significant effects.

---

## 7. How we share information

We share personal data only in these situations:

- **Within your Organization** — administrators and authorized attendance checkers of your Organization can view your registration and attendance records. Access is scoped by role, organization, and (for checkers) optionally by specific events.
- **Service providers (sub-processors)** acting on our instructions:
    - **Hosting / infrastructure:** [HOSTING_PROVIDER]
    - **Email delivery:** [EMAIL_PROVIDER]
    - **File/object storage:** [STORAGE_PROVIDER]
    - **Identity providers:** Google, Microsoft, Meta (only when you choose SSO)
- **Legal and safety** — when required by law, subpoena, or to protect the rights, property, or safety of users, the Organization, or the public.
- **Business transfer** — in a merger, acquisition, or asset sale, with notice and equivalent protections.

**We do not sell your personal information, and we do not share it for cross-context behavioural advertising.**

---

## 8. Data retention

| Data                                       | Retention                                                                                                                            |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| Account and profile data                   | While your account is active, then deleted or anonymized within **[RETENTION_ACCOUNT, e.g. 30]** days of a verified deletion request |
| Event registrations and attendance records | For **[RETENTION_ATTENDANCE, e.g. 24 months]** after the event, or as required by the Organization's record-keeping obligations      |
| ID cards (PDF/images)                      | Deleted when the related registration is deleted                                                                                     |
| Audit logs (including IP and user-agent)   | **[RETENTION_AUDIT, e.g. 12 months]**, then purged                                                                                   |
| SSO linked-identity records                | Deleted with your account                                                                                                            |
| Backups                                    | Purged on the rolling backup cycle, up to **[RETENTION_BACKUP, e.g. 35]** days                                                       |

Attendance data may be retained in aggregated, non-identifying form for statistics after individual records are deleted.

---

## 9. Your rights and data deletion instructions

Depending on your jurisdiction (GDPR, UK GDPR, CCPA/CPRA, the Philippine Data Privacy Act of 2012, and others), you may have the right to:

- **Access** a copy of the personal data we hold about you
- **Correct** inaccurate or incomplete data
- **Delete** your data ("right to erasure")
- **Object to** or **restrict** processing
- **Data portability** — receive your data in a machine-readable format
- **Withdraw consent** at any time, without affecting prior lawful processing
- **Lodge a complaint** with your supervisory authority (e.g. the National Privacy Commission in the Philippines, or your EU/UK data protection authority)

### How to request deletion of your data

You can request deletion in any of these ways:

1. **In the app** — sign in and use _Account → Delete my account_ (where available), or ask your Organization administrator to delete your attendee record.
2. **By email** — send a request to **[PRIVACY_CONTACT_EMAIL]** from the email address on your account, with the subject line **"Data Deletion Request"**. Include your full name and the Organization/event you attended.
3. **Via the deletion request page** — **[DATA_DELETION_URL]**

**What happens next:** we verify your identity (normally by confirming control of the account email), then delete or irreversibly anonymize your profile, photos, SSO linked identities, registrations, generated ID cards, and attendance records within **[RETENTION_ACCOUNT, e.g. 30] days**. We will confirm by email when deletion is complete.

**What we may keep:** immutable audit-log entries and records we are legally required to retain. These are minimized and retained only for the periods in Section 8. Aggregate statistics that cannot identify you may be retained indefinitely.

**Facebook-initiated deletion:** if you remove the app from your Facebook account, Meta sends us a data deletion callback. We process it as a deletion request under this section and provide a confirmation code and status URL you can use to track it.

We respond to rights requests within **30 days** (extendable where the law permits, with notice). Exercising your rights is free and will not result in discriminatory treatment.

---

## 10. Security

We apply technical and organizational safeguards including:

- Passwords stored only as salted one-way hashes — never in plaintext or reversible form
- Encryption in transit (HTTPS/TLS) for all traffic
- API access via revocable bearer tokens rather than long-lived shared secrets
- Role-based access control and per-organization data isolation, so one Organization cannot see another's records
- Opaque, non-guessable, single-use QR tokens that expose no personal data
- Immutable audit logging of administrative actions
- Least-privilege access for operational staff

No system is perfectly secure. If we become aware of a breach affecting your personal data, we will notify you and the relevant supervisory authority as required by law.

---

## 11. Cookies and similar technologies

The Service is API-driven and uses only **strictly necessary** cookies and local storage for session management, authentication tokens, and CSRF protection. We do **not** use advertising cookies, third-party trackers, analytics pixels, or cross-site tracking. Blocking these strictly necessary cookies will prevent you from signing in.

---

## 12. Children's privacy

The Service is not directed at children under **13** (or the minimum age in your jurisdiction — **16** in parts of the EEA). We do not knowingly collect data directly from children. Where an Organization registers a minor for an event, the Organization is responsible for obtaining verifiable parental or guardian consent. If you believe we hold a child's data without proper consent, contact **[PRIVACY_CONTACT_EMAIL]** and we will delete it promptly.

---

## 13. International data transfers

Your data may be processed in **[PRIMARY_HOSTING_COUNTRY]** and other countries where our service providers operate. Where data leaves your jurisdiction, we rely on appropriate safeguards such as the EU Standard Contractual Clauses, the UK International Data Transfer Addendum, or an adequacy decision.

---

## 14. Third-party links and services

The Service links to third-party identity providers (Google, Microsoft, Meta). Their handling of your data is governed by their own policies:

- [Google Privacy Policy](https://policies.google.com/privacy)
- [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement)
- [Meta Privacy Policy](https://www.facebook.com/privacy/policy)

We are not responsible for the privacy practices of third parties.

---

## 15. Changes to this Policy

We may update this Policy from time to time. Material changes will be announced in-app and/or by email at least **[NOTICE_PERIOD, e.g. 14]** days before they take effect, and the "Last updated" date above will change. Continued use of the Service after the effective date constitutes acceptance.

---

## 16. Contact us

**[LEGAL_ENTITY_NAME]**
Email: **[PRIVACY_CONTACT_EMAIL]**
Address: **[ORGANIZATION_POSTAL_ADDRESS]**
Privacy policy URL: **[PUBLIC_PRIVACY_POLICY_URL]**
Data deletion URL: **[DATA_DELETION_URL]**
Terms of Service: **[TERMS_OF_SERVICE_URL]**
