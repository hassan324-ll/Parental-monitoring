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
class LocalPairingRecoveryTest {
    private val old = "a".repeat(64)
    private val next = "b".repeat(64)
    private class Store(var journal: CredentialJournal?) : RotatingDeviceCredentialStore {
        var clearCalls = 0
        var clearFailure = false
        var ignoreClear = false
        var readFailure = false
        var failReadAfterClear = false
        var saveFailure = false
        var clearGate: CompletableDeferred<Unit>? = null
        override suspend fun prepare() { }
        override suspend fun read() = readJournal()?.current
        override suspend fun readJournal(): CredentialJournal? {
            if (readFailure || (failReadAfterClear && clearCalls > 0)) throw IOException("synthetic read failure")
            return journal
        }
        override suspend fun save(token: String) {
            if (saveFailure) throw IOException("synthetic write failure")
            journal = CredentialJournal(token)
        }
        override suspend fun stage(current: String, pending: String) { journal = CredentialJournal(current, pending) }
        override suspend fun promote(current: String, pending: String) { journal = CredentialJournal(pending) }
        override suspend fun clear() {
            clearCalls++
            clearGate?.await()
            if (clearFailure) throw IOException("synthetic cleanup failure")
            if (!ignoreClear) { journal = null; readFailure = false }
        }
    }
    private inner class Api : CompanionSessionApi, CompanionRotationApi, PairingApprovalApi {
        var requests = 0
        var rejected = true
        var statusGate: CompletableDeferred<Unit>? = null
        override suspend fun status(credential: String): CompanionDevice {
            requests++
            statusGate?.await()
            if (rejected) throw DeviceUnauthorizedException()
            return CompanionDevice("synthetic-id", "Synthetic phone")
        }
        override suspend fun disconnect(credential: String) { requests++; throw IOException("synthetic offline") }
        override suspend fun rotate(currentCredential: String, nextCredentialHash: String) { requests++ }
        override suspend fun approve(code: String, deviceName: String, androidVersion: String): PairingRepository.Approval {
            requests++
            return PairingRepository.Approval(next, "Synthetic phone")
        }
    }
    private suspend fun recovery(store: Store, api: Api): DeviceSession = DeviceSession(store, api, api).also {
        it.restore()
        assertEquals(DeviceSession.Phase.ERROR, it.state.value.phase)
    }

    @Test fun acknowledgmentIsRequiredAndNoNetworkRequestIsSentByLocalErase() = runTest {
        val store = Store(CredentialJournal(old, next))
        val api = Api()
        val session = recovery(store, api)
        val requests = api.requests
        session.forgetLocalPairing()
        assertEquals(0, store.clearCalls)
        assertNotNull(store.journal)
        session.forgetLocalPairing(true)
        assertNull(store.journal)
        assertEquals(requests, api.requests)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
        assertTrue(session.state.value.message.contains("did not revoke server access"))
        assertFalse(session.state.value.toString().contains(old))
        assertFalse(session.state.value.toString().contains(next))
        assertFalse(session.state.value.canForgetLocalPairing)
    }

    @Test fun connectedAndUnpairedSessionsCannotBeErasedThroughRecoveryAction() = runTest {
        val store = Store(CredentialJournal(old))
        val api = Api().apply { rejected = false }
        val session = DeviceSession(store, api)
        session.forgetLocalPairing(true)
        session.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        session.forgetLocalPairing(true)
        assertEquals(0, store.clearCalls)
        assertEquals(old, store.journal?.current)
    }

    @Test fun brokenKeyOrCiphertextCanBeClearedWithoutReadingItFirst() = runTest {
        val store = Store(CredentialJournal(old)).apply { readFailure = true }
        val api = Api()
        val session = recovery(store, api)
        session.forgetLocalPairing(true)
        assertNull(store.journal)
        assertEquals(0, api.requests)
    }

    @Test fun failedClearRetainsRecoveryAndBlocksApprovalUntilExplicitRetrySucceeds() = runTest {
        val store = Store(CredentialJournal(old, next)).apply { clearFailure = true }
        val api = Api()
        val session = recovery(store, api)
        session.forgetLocalPairing(true)
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(session.state.value.canForgetLocalPairing)
        assertNotNull(store.journal)
        val requests = api.requests
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve("A".repeat(32), "Phone", "26"))
        assertEquals(requests, api.requests)
        store.clearFailure = false
        session.forgetLocalPairing(true)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun falseSuccessfulClearCannotUnlockPairing() = runTest {
        val store = Store(CredentialJournal(old, next)).apply { ignoreClear = true }
        val api = Api()
        val session = recovery(store, api)
        session.forgetLocalPairing(true)
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(session.state.value.message.contains("could not be verified"))
        session.restore()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
    }

    @Test fun failedReadbackBlocksPairingEvenIfClearActuallyRemovedData() = runTest {
        val store = Store(CredentialJournal(old, next)).apply { failReadAfterClear = true }
        val api = Api()
        val session = recovery(store, api)
        session.forgetLocalPairing(true)
        assertNull(store.journal)
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        store.failReadAfterClear = false
        session.restore()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        val requests = api.requests
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve("A".repeat(32), "Phone", "26"))
        assertEquals(requests, api.requests)
        session.forgetLocalPairing(true)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun staleConfirmationWaitsForRestoreAndCannotEraseNewlyVerifiedSession() = runTest {
        val store = Store(CredentialJournal(old, next))
        val api = Api()
        val session = recovery(store, api)
        store.journal = CredentialJournal(old)
        api.rejected = false
        api.statusGate = CompletableDeferred()
        val restoring = launch { session.restore() }
        runCurrent()
        val erasing = launch { session.forgetLocalPairing(true) }
        runCurrent()
        assertEquals(0, store.clearCalls)
        api.statusGate!!.complete(Unit)
        restoring.join(); erasing.join()
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        assertEquals(0, store.clearCalls)
    }

    @Test fun initiatedCleanupFinishesAfterCallerCancellationWithoutReleasingSessionLockEarly() = runTest {
        val store = Store(CredentialJournal(old, next)).apply { clearGate = CompletableDeferred() }
        val api = Api()
        val session = recovery(store, api)
        val erasing = launch { session.forgetLocalPairing(true) }
        runCurrent()
        erasing.cancel()
        val restoring = launch { session.restore() }
        runCurrent()
        assertEquals(DeviceSession.Phase.DISCONNECTING, session.state.value.phase)
        assertFalse(restoring.isCompleted)
        store.clearGate!!.complete(Unit)
        erasing.join(); restoring.join()
        assertNull(store.journal)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun forgottenCompensationTokenCannotBeUsedBySubsequentDisconnect() = runTest {
        val store = Store(null).apply { saveFailure = true }
        val api = Api()
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.RECOVERY_REQUIRED, session.approve("A".repeat(32), "Phone", "26"))
        session.forgetLocalPairing(true)
        val requests = api.requests
        session.disconnect()
        assertEquals(requests, api.requests)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }
}
