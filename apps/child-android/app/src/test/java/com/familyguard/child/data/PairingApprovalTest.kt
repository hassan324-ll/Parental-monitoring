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
class PairingApprovalTest {
    private val token = "b".repeat(64)
    private val code = "A".repeat(32)
    private class Store : DeviceCredentialStore {
        var value: String? = null
        var prepared = false
        var prepareFailure = false
        var saveFailure = false
        var clearFailure = false
        var loseWrite = false
        override suspend fun prepare() {
            if (prepareFailure) throw IOException("synthetic prepare failure")
            prepared = true
        }
        override suspend fun save(token: String) {
            check(prepared)
            if (saveFailure) throw IOException("synthetic save failure")
            if (!loseWrite) value = token
        }
        override suspend fun read() = value
        override suspend fun clear() {
            if (clearFailure) throw IOException("synthetic cleanup failure")
            value = null
        }
    }
    private inner class Api(private val store: Store) : CompanionSessionApi, PairingApprovalApi {
        var approvals = 0
        var disconnects = 0
        var disconnectFailure = false
        var approvalFailure = false
        var offlineStatus = false
        var revokedStatus = false
        var response: CompletableDeferred<PairingRepository.Approval>? = null
        override suspend fun approve(code: String, deviceName: String, androidVersion: String): PairingRepository.Approval {
            check(store.prepared)
            assertEquals(this@PairingApprovalTest.code, code)
            approvals++
            if (approvalFailure) throw IOException("synthetic uncertain response")
            return response?.await() ?: PairingRepository.Approval(token, "Synthetic phone")
        }
        override suspend fun status(credential: String): CompanionDevice {
            assertEquals(token, credential)
            if (revokedStatus) throw DeviceUnauthorizedException()
            if (offlineStatus) throw IOException("synthetic offline")
            return CompanionDevice("synthetic-id", "Synthetic phone")
        }
        override suspend fun disconnect(credential: String) {
            assertEquals(token, credential)
            disconnects++
            if (disconnectFailure) throw IOException("synthetic offline")
        }
    }

    @Test fun storageReadinessAndDurableReadbackPrecedeVerifiedPairing() = runTest {
        val store = Store()
        val api = Api(store)
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.VERIFIED, session.approve(code, "Phone", "15"))
        assertEquals(token, store.value)
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        assertFalse(session.state.value.toString().contains(token))
        assertFalse(PairingRepository.Approval(token, "Phone").toString().contains(token))
        val restartedSession = DeviceSession(store, api, api)
        restartedSession.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, restartedSession.state.value.phase)
    }

    @Test fun unavailableStorageDoesNotConsumeInvitation() = runTest {
        val store = Store().apply { prepareFailure = true }
        val api = Api(store)
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve(code, "Phone", "15"))
        assertEquals(0, api.approvals)
        assertNull(store.value)
    }

    @Test fun existingCredentialCannotBeOverwrittenByNewApproval() = runTest {
        val store = Store().apply { value = "a".repeat(64) }
        val api = Api(store)
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve(code, "Phone", "15"))
        assertEquals(0, api.approvals)
        assertEquals("a".repeat(64), store.value)
    }

    @Test fun rejectedOrUnconfirmedApprovalNeverClaimsSavedCredential() = runTest {
        val store = Store()
        val api = Api(store).apply { approvalFailure = true }
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve(code, "Phone", "15"))
        assertNull(store.value)
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(session.state.value.message.contains("unconfirmed"))
    }

    @Test fun failedPersistenceCompensatesWithServerDisconnect() = runTest {
        val store = Store().apply { saveFailure = true }
        val api = Api(store)
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve(code, "Phone", "15"))
        assertEquals(1, api.disconnects)
        assertNull(store.value)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun silentLostWriteIsDetectedAndRevoked() = runTest {
        val store = Store().apply { loseWrite = true }
        val api = Api(store)
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve(code, "Phone", "15"))
        assertEquals(1, api.disconnects)
        assertNull(store.value)
    }

    @Test fun offlineCompensationPreservesRecoveryAndBlocksAnotherPairing() = runTest {
        val store = Store().apply { saveFailure = true }
        val api = Api(store).apply { disconnectFailure = true }
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.RECOVERY_REQUIRED, session.approve(code, "Phone", "15"))
        session.restore()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        session.approve(code, "Phone", "15")
        assertEquals(1, api.approvals)
        api.disconnectFailure = false
        session.disconnect()
        assertEquals(2, api.disconnects)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun cleanupFailureAfterCompensationCanRetryWithInMemoryCredential() = runTest {
        val store = Store().apply { saveFailure = true; clearFailure = true }
        val api = Api(store)
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.RECOVERY_REQUIRED, session.approve(code, "Phone", "15"))
        store.clearFailure = false
        session.disconnect()
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun savedButOfflineCredentialNeverClaimsVerifiedConnection() = runTest {
        val store = Store()
        val api = Api(store).apply { offlineStatus = true }
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.SAVED_UNVERIFIED, session.approve(code, "Phone", "15"))
        assertEquals(token, store.value)
        assertEquals(DeviceSession.Phase.OFFLINE, session.state.value.phase)
        assertNull(session.state.value.device)
    }

    @Test fun immediateServerRevocationClearsNewCredential() = runTest {
        val store = Store()
        val api = Api(store).apply { revokedStatus = true }
        val session = DeviceSession(store, api, api)
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve(code, "Phone", "15"))
        assertNull(store.value)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun leavingUiDuringApprovalDoesNotDiscardReturnedCredential() = runTest {
        val store = Store()
        val api = Api(store).apply { response = CompletableDeferred() }
        val session = DeviceSession(store, api, api)
        val operation = launch { session.approve(code, "Phone", "15") }
        runCurrent()
        operation.cancel()
        api.response!!.complete(PairingRepository.Approval(token, "Phone"))
        operation.join()
        assertEquals(token, store.value)
        val restartedSession = DeviceSession(store, api, api)
        restartedSession.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, restartedSession.state.value.phase)
    }
}