package com.familyguard.child.data

import java.io.IOException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.*
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class SharingControlsTest {
    private val token = "e".repeat(64)
    private class Store(var token: String?) : DeviceCredentialStore {
        override suspend fun prepare() { }
        override suspend fun save(token: String) { this.token = token }
        override suspend fun read() = token
        override suspend fun clear() { token = null }
    }
    private inner class Api : CompanionSessionApi, DeviceSharingApi {
        var usage = SharingConsent(false, 0, "7 UTC calendar days including today")
        var location = SharingConsent(false, 0, "24 hours")
        var reads = 0; var saves = 0; var stops = 0
        var failure: Exception? = null
        var gate: CompletableDeferred<Unit>? = null
        var stale = false; var ignoreDecision = false
        override suspend fun status(credential: String) = CompanionDevice("synthetic-id", "Synthetic phone")
        override suspend fun disconnect(credential: String) { }
        override suspend fun sharingStatus(credential: String): SharingSnapshot {
            assertEquals(token, credential); reads++; gate?.await(); failure?.let { throw it }
            return SharingSnapshot(usage, location)
        }
        private fun change(feature: SharingFeature, enabled: Boolean): SharingSnapshot {
            failure?.let { throw it }
            val old = if (feature == SharingFeature.USAGE) usage else location
            if (!ignoreDecision) {
                val value = old.copy(enabled = enabled, version = old.version + if (stale) 0 else 1)
                if (feature == SharingFeature.USAGE) usage = value else location = value
            }
            return SharingSnapshot(usage, location)
        }
        override suspend fun saveSharingConsent(credential: String, feature: SharingFeature): SharingSnapshot { saves++; return change(feature, true) }
        override suspend fun stopSharing(credential: String, feature: SharingFeature): SharingSnapshot { stops++; return change(feature, false) }
    }
    private suspend fun connected(store: Store, api: Api) = DeviceSession(store, api).also { it.restore() }

    @Test fun unverifiedSessionCannotReadOrChangePreferences() = runTest {
        val api = Api(); val s = DeviceSession(Store(token), api)
        s.refreshSharing(); s.decideSharing(SharingFeature.USAGE, true, true, s.sharing.value.revision)
        assertEquals(0, api.reads); assertEquals(0, api.saves)
        assertNull(s.sharing.value.snapshot)
    }
    @Test fun verifiedReadIsIndependentDefaultOffAndNeverExposesCredential() = runTest {
        val api = Api(); val s = connected(Store(token), api); s.refreshSharing()
        assertEquals(SharingState.Phase.READY, s.sharing.value.phase)
        assertFalse(s.sharing.value.snapshot!!.usage.enabled)
        assertFalse(s.sharing.value.snapshot!!.location.enabled)
        assertFalse(s.sharing.value.toString().contains(token))
    }
    @Test fun savingRequiresFreshReadAndExplicitAcknowledgment() = runTest {
        val api = Api(); val s = connected(Store(token), api)
        s.decideSharing(SharingFeature.USAGE, true, true, s.sharing.value.revision)
        assertEquals(0, api.saves)
        s.refreshSharing(); val revision = s.sharing.value.revision
        s.decideSharing(SharingFeature.USAGE, true, false, revision)
        assertEquals(0, api.saves)
        s.decideSharing(SharingFeature.USAGE, true, true, revision)
        assertEquals(1, api.saves)
        assertTrue(s.sharing.value.snapshot!!.usage.enabled)
        assertFalse(s.sharing.value.snapshot!!.location.enabled)
        assertTrue(s.sharing.value.message.contains("unavailable"))
    }
    @Test fun repeatedOrStaleConfirmationCannotSendAnotherDecision() = runTest {
        val api = Api(); val s = connected(Store(token), api); s.refreshSharing()
        val revision = s.sharing.value.revision
        s.decideSharing(SharingFeature.USAGE, true, true, revision)
        s.decideSharing(SharingFeature.USAGE, true, true, revision)
        assertEquals(1, api.saves)
        assertNull(s.sharing.value.snapshot)
    }
    @Test fun identityVerificationInvalidatesAnOpenConfirmation() = runTest {
        val api = Api(); val s = connected(Store(token), api); s.refreshSharing()
        val revision = s.sharing.value.revision
        s.restore(); s.decideSharing(SharingFeature.USAGE, true, true, revision)
        assertEquals(0, api.saves)
        assertNull(s.sharing.value.snapshot)
    }
    @Test fun offlineDecisionDoesNotClaimSavedOrDeletionSuccessAndCanBeVerifiedLater() = runTest {
        val api = Api(); val s = connected(Store(token), api); s.refreshSharing()
        api.failure = IOException("synthetic offline")
        s.decideSharing(SharingFeature.USAGE, false, false, s.sharing.value.revision)
        assertEquals(SharingState.Phase.ERROR, s.sharing.value.phase)
        assertNull(s.sharing.value.snapshot)
        assertTrue(s.sharing.value.message.contains("Cannot confirm"))
        api.failure = null; s.refreshSharing()
        assertEquals(SharingState.Phase.READY, s.sharing.value.phase)
    }
    @Test fun stopRemainsExplicitlyRetryableAfterUnknownStatus() = runTest {
        val api = Api(); val s = connected(Store(token), api)
        api.failure = IOException("synthetic offline"); s.refreshSharing()
        api.failure = null
        s.decideSharing(SharingFeature.LOCATION, false, false, s.sharing.value.revision)
        assertEquals(1, api.stops)
        assertFalse(s.sharing.value.snapshot!!.location.enabled)
        assertEquals(0, api.saves)
    }
    @Test fun rejectedBearerInvalidatesBothConsentDisplayAndSessionAuthorization() = runTest {
        val api = Api(); val s = connected(Store(token), api); s.refreshSharing()
        api.failure = DeviceUnauthorizedException(); s.refreshSharing()
        assertEquals(DeviceSession.Phase.ERROR, s.state.value.phase)
        assertNull(s.sharing.value.snapshot)
        s.decideSharing(SharingFeature.USAGE, true, true, s.sharing.value.revision)
        assertEquals(0, api.saves)
    }
    @Test fun cancelledReadHidesPriorSnapshotAndDoesNotSendConsent() = runTest {
        val api = Api(); val s = connected(Store(token), api); s.refreshSharing()
        api.gate = CompletableDeferred()
        val job = launch { s.refreshSharing() }; runCurrent()
        assertNull(s.sharing.value.snapshot)
        job.cancel(); job.join()
        assertEquals(SharingState.Phase.ERROR, s.sharing.value.phase)
        assertEquals(0, api.saves)
    }
    @Test fun disconnectSerializesAfterReadAndClearsPublishedPreferences() = runTest {
        val api = Api(); val store = Store(token); val s = connected(store, api)
        api.gate = CompletableDeferred()
        val reading = launch { s.refreshSharing() }; runCurrent()
        val disconnecting = launch { s.disconnect() }; runCurrent()
        assertFalse(disconnecting.isCompleted)
        api.gate!!.complete(Unit); reading.join(); disconnecting.join()
        assertNull(s.sharing.value.snapshot)
        assertEquals(DeviceSession.Phase.UNPAIRED, s.state.value.phase)
    }
    @Test fun staleAndWrongDecisionResponsesNeverPublishSuccess() = runTest {
        for (wrong in listOf(false, true)) {
            val api = Api().apply { stale = !wrong; ignoreDecision = wrong }
            val s = connected(Store(token), api); s.refreshSharing()
            s.decideSharing(SharingFeature.USAGE, true, true, s.sharing.value.revision)
            assertEquals(SharingState.Phase.ERROR, s.sharing.value.phase)
            assertNull(s.sharing.value.snapshot)
        }
    }
}
