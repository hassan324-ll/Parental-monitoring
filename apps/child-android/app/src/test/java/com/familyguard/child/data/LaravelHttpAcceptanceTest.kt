package com.familyguard.child.data

import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Assume.assumeTrue
import org.junit.Test

/** Opt-in real Laravel HTTP acceptance, launched only by scripts/test-device-http.ps1. */
class LaravelHttpAcceptanceTest {
    private class Store : ApprovingDeviceCredentialStore {
        var encoded: String? = null
        override suspend fun prepare() { }
        override suspend fun readJournal() = encoded?.let(CredentialJournalCodec::decode)
        override suspend fun read() = readJournal()?.current
        override suspend fun save(token: String) { encoded = CredentialJournalCodec.encode(CredentialJournal(token)) }
        override suspend fun stageApproval(credential: String) {
            check(encoded == null)
            encoded = CredentialJournalCodec.encode(CredentialJournal(credential, approvalPending = true))
        }
        override suspend fun confirmApproval(credential: String) {
            check(readJournal()?.current == credential && readJournal()?.approvalPending == true)
            save(credential)
        }
        override suspend fun stage(current: String, pending: String) {
            check(readJournal()?.current == current)
            encoded = CredentialJournalCodec.encode(CredentialJournal(current, pending))
        }
        override suspend fun promote(current: String, pending: String) {
            check(readJournal()?.current == current && readJournal()?.pending == pending)
            save(pending)
        }
        override suspend fun clear() { encoded = null }
    }

    @Test fun realLaravelPairingRestoreRotationDisconnectAndRevocation() = runBlocking {
        assumeTrue("Run scripts/test-device-http.ps1 for isolated live HTTP acceptance.",
            System.getenv("FAMILYGUARD_HTTP_ACCEPTANCE") == "1")
        val base = URL(requireNotNull(System.getenv("FAMILYGUARD_HTTP_URL")))
        require(base.protocol == "http" && base.host == "127.0.0.1" && base.port > 0)
        val fixturePath = requireNotNull(System.getenv("FAMILYGUARD_HTTP_FIXTURE"))
        val fixture = JSONObject(File(fixturePath).readText())
        val codes = fixture.getJSONObject("codes")
        val factory: (URL) -> HttpURLConnection = { original ->
            check(original.host == "10.0.2.2")
            URL("${base.protocol}://${base.host}:${base.port}${original.file}").openConnection() as HttpURLConnection
        }
        val pairing = PairingRepository(factory)
        val api = CompanionSessionRepository(factory)
        assertEquals("HTTP synthetic child", pairing.preview(codes.getString("primary")).childName)
        val store = Store()
        val first = CredentialTokens.generate()
        val replacement = CredentialTokens.generate()
        val session = DeviceSession(store, api, pairing, { replacement }, { first })
        assertEquals(DeviceSession.ApprovalResult.VERIFIED, session.approve(codes.getString("primary"), "HTTP synthetic phone", "26"))
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        assertFalse(store.readJournal()!!.approvalPending)
        val restored = DeviceSession(store, api, pairing, { replacement })
        restored.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, restored.state.value.phase)
        assertFalse(session.state.value.toString().contains(first))
        restored.refreshSharing()
        assertEquals(SharingState.Phase.READY, restored.sharing.value.phase)
        assertFalse(restored.sharing.value.snapshot!!.usage.enabled)
        assertFalse(restored.sharing.value.snapshot!!.location.enabled)
        restored.decideSharing(SharingFeature.USAGE, true, true, restored.sharing.value.revision)
        assertEquals(SharingState.Phase.READY, restored.sharing.value.phase)
        assertTrue(restored.sharing.value.snapshot!!.usage.enabled)
        assertFalse(restored.sharing.value.snapshot!!.location.enabled)
        restored.decideSharing(SharingFeature.USAGE, false, false, restored.sharing.value.revision)
        assertEquals(SharingState.Phase.READY, restored.sharing.value.phase)
        assertFalse(restored.sharing.value.snapshot!!.usage.enabled)
        restored.decideSharing(SharingFeature.LOCATION, true, true, restored.sharing.value.revision)
        assertEquals(SharingState.Phase.READY, restored.sharing.value.phase)
        assertTrue(restored.sharing.value.snapshot!!.location.enabled)
        restored.rotate()
        assertEquals(DeviceSession.Phase.CONNECTED, restored.state.value.phase)
        assertEquals(replacement, store.readJournal()?.current)
        try { api.status(first); fail("Old credential remained authorized") } catch (expected: DeviceUnauthorizedException) { }
        try {
            pairing.approveStaged(codes.getString("primary"), "HTTP synthetic phone", "26", CredentialTokens.hash(first))
            fail("Consumed invitation replay succeeded")
        } catch (expected: IllegalStateException) { assertTrue(expected.message!!.contains("404")) }
        restored.disconnect()
        assertNull(restored.sharing.value.snapshot)
        assertNull(store.readJournal())
        assertEquals(DeviceSession.Phase.UNPAIRED, restored.state.value.phase)
        try { api.status(replacement); fail("Disconnected credential remained authorized") } catch (expected: DeviceUnauthorizedException) { }
        try {
            pairing.approveStaged(codes.getString("expired"), "HTTP synthetic phone", "26", CredentialTokens.hash(CredentialTokens.generate()))
            fail("Expired invitation succeeded")
        } catch (expected: IllegalStateException) { assertTrue(expected.message!!.contains("404")) }
        val revokedStore = Store()
        val revocable = DeviceSession(revokedStore, api, pairing)
        assertEquals(DeviceSession.ApprovalResult.VERIFIED, revocable.approve(codes.getString("revoked"), "HTTP revocable phone", "26"))
        val command = ProcessBuilder(requireNotNull(System.getenv("FAMILYGUARD_HTTP_PHP")),
            requireNotNull(System.getenv("FAMILYGUARD_HTTP_FIXTURE_SCRIPT")), "revoke", fixturePath)
            .redirectErrorStream(true).start()
        val output = command.inputStream.bufferedReader().use { it.readText() }
        assertEquals("Synthetic revocation helper failed; inspect local logs without printing fixtures.", 0, command.waitFor())
        assertTrue(output.contains("devices revoked"))
        revocable.restore()
        assertEquals(DeviceSession.Phase.UNPAIRED, revocable.state.value.phase)
        assertNull(revokedStore.readJournal())
        val unauthenticated = URL("${base.protocol}://${base.host}:${base.port}/api/v1/families").openConnection() as HttpURLConnection
        try { assertEquals(401, unauthenticated.responseCode) } finally { unauthenticated.disconnect() }
    }
}
