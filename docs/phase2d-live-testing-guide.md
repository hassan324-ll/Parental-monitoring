# FamilyGuard Phase 2D — live testing on Windows

Use a synthetic parent name and an email inbox you control. Keep your password and verification link private. The disposable automated provider test used a reserved invalid email domain; it cannot demonstrate inbox delivery.

## Start and stop

Open Windows PowerShell and paste:

~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard'
.\scripts\start-familyguard.ps1
~~~

The launcher checks dependencies, starts/verifies the private PostgreSQL cluster on 127.0.0.1:5433, starts Laravel and Angular on loopback, checks HTTP readiness, and reuses this checkout's existing listeners. It refuses a port owned by another application or a public listener. Background web services remain running after the launcher exits; this terminal may close. Logs are ignored files in .local/run/backend.*.log and frontend.*.log. Closing the terminal does not stop them.

~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard'
.\scripts\stop-familyguard.ps1
# Optional: stop this project's private database as well:
.\scripts\stop-familyguard.ps1 -StopDatabase
~~~

If PowerShell blocks local scripts, use this process-only command (no machine policy change):

~~~powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-familyguard.ps1
~~~

Separate terminal fallback: run start-database.ps1 first; run start-backend.ps1 in terminal A and leave it open; run start-frontend.ps1 in terminal B and leave it open. Ctrl+C stops each foreground server. The launcher is already tested for fresh web startup and repeated reuse.

## Firebase Console checks

Open https://console.firebase.google.com/project/family-guard-eb08c/authentication/providers and confirm Email/Password is enabled. Authentication → Settings → Authorized domains must contain localhost. The live public project-configuration request confirmed localhost is already present. Firebase no longer adds it by default for projects created after April 28, 2025: https://firebase.google.com/docs/auth/faq-and-troubleshooting . Do not enable email-link sign-in merely for password-based verification/reset links.

Public web config: Project settings → General → Your apps → Web app → SDK setup and configuration → Config. Development config now matches the exact public object supplied by the owner. Backend ignored .env has FIREBASE_PROJECT_ID=family-guard-eb08c. Production config stays null. Never paste passwords, ID tokens, verification links or Admin private keys into chat/issues.

## Nine manual tests

| Test | Action | Expected result / success | If it fails |
| --- | --- | --- | --- |
| 1. Open | Visit http://localhost:4200/auth . | Welcome back, email/password fields; empty submission disabled. | Rerun launcher; inspect .local/run/frontend.err.log. Check http://localhost:8000/api/v1/health returns status ok. |
| 2. Register | Click Create account. Use Synthetic Parent A, an inbox you own and a private password of at least 8 characters. First try empty fields/invalid email/short password and leave the fields. | Field errors appear; invalid submission stays disabled. Valid registration returns to sign-in with a check-email notice; no protected session persists. | Confirm provider enabled/config correct. Existing email: use reset or another inbox you own. Do not repeatedly register the same account. |
| 3. Verify email | Open inbox/spam, find Firebase verification email and follow its verification link yourself. | Firebase confirms email verification. Return to FamilyGuard. | Check spam, provider quotas and Authentication templates. If delivery failed after account creation, do not assume registration was rolled back; inspect that synthetic account in Console and use its normal verification controls. Never mark arbitrary users verified to bypass this step. |
| 4. Login | Before verification, try login: access must be denied. After verification, sign in with your private credentials. | Before: verify-email error; after: navigation to dashboard. | Finish verification, check credentials and browser console; use Forgot password? if needed. A generic UI failure is not proof the provider succeeded. |
| 5. Dashboard | Open http://localhost:4200/dashboard and reload the page. Click Check connection. | Verified session restores; dashboard remains accessible; API available appears. No devices/session data is expected yet. | If redirected, sign in again and check verification state; if API unavailable, rerun launcher and inspect backend logs. |
| 6. Laravel identity | Click Verify with Laravel. | Identity verified by Laravel plus your synthetic parent's name; network GET /api/v1/me is 200. | A 401 means Laravel did not accept the token; confirm backend project ID/restart and Google certificate connectivity. Never disable token verification. |
| 7. Family API | There is no family-creation UI yet. Use the local PowerShell API test below after verification. | /me 200; family/child creation 201; own family and child list 200. | 401: identity; 403: insufficient role; 404: inaccessible/missing family; 422: input validation. Keep output private if you later use real family data. |
| 8. Logout | Click Sign out, then visit /dashboard and reload. | Redirect to /auth?next=...; no protected dashboard. | Close tabs, repeat logout and inspect console. Client logout does not revoke a copied server token; immediate revocation checks are still a production blocker. |
| 9. Isolation | Open a private browser window without logging in; visit /dashboard and /api/v1/families. With another verified synthetic parent B, use the API command against A's family ID. | Anonymous dashboard redirects; anonymous API returns 401 (HTML browser requests may show an auth response). Parent B receives 404 for A's children, never A's data. | Stop manual use and report any cross-family data disclosure. Do not use another person's credentials. Cross-family denial already passes automated signed-token and authorization tests. |

Password reset: Forgot password? → enter your owned test inbox → Send reset email. The UI intentionally always shows a generic response. Success requires receiving the email and completing the reset yourself, then logging in using the new password. Automated tests verified the provider accepted a reset request, not that mail arrived.

## Optional authenticated family API test (PowerShell)

Run this locally after the email is verified. It prompts locally for a hidden password; nothing is sent to chat. It signs into the named Firebase project, holds credentials/tokens only in this process, creates a synthetic family and child, and displays only status/IDs. The records remain in your development database for inspection. Do not enable PowerShell transcripts or print the session variables. Replace the optional isolation ID with A's actual synthetic family ID when testing parent B.

~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard'
$webSource = Get-Content '.\frontend\src\environments\environment.ts' -Raw
$webKey = [regex]::Match($webSource, "apiKey: '([^']+)'").Groups[1].Value
$testEmail = Read-Host 'Verified synthetic parent email'
$testSecret = Read-Host 'Password (local only)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($testSecret)
try {
    $loginBody = @{email=$testEmail;password=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer);returnSecureToken=$true} | ConvertTo-Json
    $session = Invoke-RestMethod -Method Post -Uri ("https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=" + $webKey) -ContentType 'application/json' -Body $loginBody
    $apiHeaders = @{Authorization=('Bearer ' + $session.idToken);Accept='application/json'}
    $me = Invoke-RestMethod 'http://localhost:8000/api/v1/me' -Headers $apiHeaders
    Write-Host 'Laravel identity accepted (HTTP 200).'
    $family = Invoke-RestMethod 'http://localhost:8000/api/v1/families' -Method Post -Headers $apiHeaders -ContentType 'application/json' -Body '{"name":"Synthetic Phase 2D Family"}'
    Write-Host ('Family created: ' + $family.data.id)
    $families = Invoke-RestMethod 'http://localhost:8000/api/v1/families' -Headers $apiHeaders
    $childUrl = 'http://localhost:8000/api/v1/families/' + $family.data.id + '/children'
    $child = Invoke-RestMethod $childUrl -Method Post -Headers $apiHeaders -ContentType 'application/json' -Body '{"display_name":"Synthetic Child"}'
    $children = Invoke-RestMethod $childUrl -Headers $apiHeaders
    Write-Host ('Child created: ' + $child.data.id + '; listed children: ' + $children.meta.total)
    # Parent B isolation test (replace 123 with parent A's family ID):
    # Invoke-RestMethod 'http://localhost:8000/api/v1/families/123/children' -Headers $apiHeaders
    # Expected: HTTP 404; never the other family's records.
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    Remove-Variable loginBody,session,apiHeaders,testSecret -ErrorAction SilentlyContinue
}
~~~

This separate REST test does not change the browser's sign-in state. Deleting synthetic Firebase accounts after testing is an owner action in Authentication → Users. No family-delete endpoint exists yet; do not reset the development database to clean up.

## Repeat automated checks

~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard'
. .\scripts\common.ps1
Push-Location backend
& $php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml
& $php vendor/phpunit/phpunit/phpunit --configuration phpunit.postgres.xml
& $php vendor/bin/pint --test
Pop-Location
Push-Location frontend
npm.cmd test -- --watch=false
npx.cmd tsc --noEmit -p tsconfig.app.json
npx.cmd tsc --noEmit -p tsconfig.spec.json
npx.cmd prettier --check 'src/**/*.{ts,html,scss}' tsconfig.json
npm.cmd run build
Pop-Location
# Optional live provider test: creates/deletes a disposable synthetic account.
node .\scripts\test-firebase-provider.cjs
~~~

Only familyguard_test is reset by PostgreSQL tests; familyguard development data is protected by the test bootstrap guard. Device pairing, monitoring, GPS, screen sharing and family CRUD UI remain future work.
