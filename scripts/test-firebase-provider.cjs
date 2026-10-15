// Optional live test: creates and deletes one disposable Firebase account. Never prints credentials.
const fs = require("node:fs");
const { createRequire } = require("node:module");
const { randomBytes } = require("node:crypto");
const root = require("node:path").resolve(__dirname, "..");
const requireApp = createRequire(root + "/apps/parent-dashboard/package.json");
const appSdk = requireApp("firebase/app");
const sdk = requireApp("firebase/auth");
const source = fs.readFileSync(
  root + "/apps/parent-dashboard/src/environments/environment.ts",
  "utf8",
);
const options = Object.fromEntries(
  ["apiKey", "authDomain", "projectId", "appId"].map((k) => [
    k,
    source.match(new RegExp(k + ": '([^']+)'"))[1],
  ]),
);
const result = { projectId: options.projectId };
let auth;
let disposableUser;
(async () => {
  const configResponse = await fetch(
    "https://identitytoolkit.googleapis.com/v1/projects?key=" + options.apiKey,
  );
  const config = await configResponse.json();
  result.projectConfigurationHttp = configResponse.status;
  if (!configResponse.ok) result.providerError = config.error?.message;
  else
    result.localhostAuthorized =
      config.authorizedDomains?.includes("localhost") ?? false;
  const app = appSdk.initializeApp(
    options,
    "familyguard-disposable-provider-smoke",
  );
  auth = sdk.initializeAuth(app, { persistence: sdk.inMemoryPersistence });
  const email =
    "phase2d-" + randomBytes(8).toString("hex") + "@example.invalid";
  const password = randomBytes(32).toString("base64url");
  const credential = await sdk.createUserWithEmailAndPassword(
    auth,
    email,
    password,
  );
  disposableUser = credential.user;
  result.registration = "provider accepted disposable synthetic account";
  await sdk.updateProfile(credential.user, {
    displayName: "Disposable Phase 2D Parent",
  });
  await sdk.sendEmailVerification(credential.user);
  result.verificationRequest =
    "provider accepted; reserved invalid domain has no inbox";
  const token = await credential.user.getIdToken();
  const response = await fetch("http://localhost:8000/api/v1/me", {
    headers: { Accept: "application/json", Authorization: "Bearer " + token },
  });
  result.unverifiedParentBackendHttp = response.status;
  if (response.status !== 401)
    throw Object.assign(new Error(), {
      code: "unexpected-unverified-backend-status",
    });
  await sdk.signOut(auth);
  result.logout = auth.currentUser === null;
  await sdk.signInWithEmailAndPassword(auth, email, password);
  result.providerSignIn = "accepted; email remains unverified";
  await sdk.sendPasswordResetEmail(auth, email);
  result.resetRequest = "provider accepted; no inbox delivery verified";
})()
  .catch((e) => {
    result.failureCode = e.code ?? e.name;
    process.exitCode = 1;
  })
  .finally(async () => {
    if (disposableUser) {
      try {
        await sdk.deleteUser(disposableUser);
        result.disposableAccountDeleted = true;
      } catch (e) {
        result.cleanupErrorCode = e.code ?? e.name;
        process.exitCode = 1;
      }
    }
    if (auth) await sdk.signOut(auth).catch(() => {});
    console.log(JSON.stringify(result, null, 2));
  });
