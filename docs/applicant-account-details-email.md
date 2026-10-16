# Application account email

The existing account-creation welcome message now explicitly identifies the applicant account, with Account Name, Login Email and Portal Sign-in Address. The subject confirms account creation and the action opens the public applicant sign-in page. The email explains that applicants use their chosen password and can use Forgot password if needed; passwords are never included in the notice. The configured admissions mailbox receives the existing separate accountability copy.

Staff-entry payment/account-access invitations also include the account name, login email and public sign-in address. Their existing private password-setup link remains only in the applicant email. No new account, password-reset or payment mechanism is added; ordinary account creation still does not create a draft application merely to produce an email reference.

Validation includes an HTTP registration through the real controller and durable mail pipeline: exactly two notices are delivered to fake mail transport, with the expected account details, sign-in action and password guidance. The password is absent from rendered email, and no admission record is created by account registration. Staff-entry invitation assertions verify its account details alongside the existing payment and private access links. Production activation pending exact-release CI.
