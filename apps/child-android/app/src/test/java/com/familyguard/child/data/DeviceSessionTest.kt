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
class DeviceSessionTest {
    private val token = "a".repeat(64)
    private val device = CompanionDevice("synthetic-public-id", "Synthetic phone")

    private class Store(var token: String?) : DeviceCredentialStore {
        var clearCalls = 0
        var readFailure = false
        var clearFailure = false
        override suspend fun prepare() { }
        override suspend fun save(token: String) { this.token = token }
        override suspend fun read(): String? {
            if (readFailure) throw IOException("synthetic storage failure")
            return token
        }
        override suspend fun clear() {
            clearCalls++
            if (clearFailure) throw IOException("synthetic cleanup failure")
            token = null
        }
    }
    private inner class Api : CompanionSessionApi {
        val statusTokens = mutableListOf<String>()
        val disconnectTokens = mutableListOf<String>()
        var statusFailure: Exception? = null
        var disconnectFailure: Exception? = null
        var pendingStatus: CompletableDeferred<CompanionDevice>? = null
        override suspend fun status(credential: String): CompanionDevice {
            statusTokens.add(credential)
            statusFailure?.let { throw it }
            return pendingStatus?.await() ?: device
        }
        override suspend fun disconnect(credential: String) {
            disconnectTokens.add(credential)
            disconnectFailure?.let { throw it }
        }
    }

    @Test fun missingCredentialDoesNotCallBackendOrClaimConnected() = runTest {
        val api = Api()
        val session = DeviceSession(Store(null), api)
        session.restore()
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
        assertTrue(api.statusTokens.isEmpty())
    }

    @Test fun restoreRequiresServerValidationAndNeverPublishesTheToken() = runTest {
        val api = Api().apply { pendingStatus = CompletableDeferred() }
        val session = DeviceSession(Store(token), api)
        val pending = launch { session.restore() }
        runCurrent()
        assertEquals(DeviceSession.Phase.CHECKING, session.state.value.phase)
        assertNull(session.state.value.device)
        api.pendingStatus!!.complete(device)
        pending.join()
        assertEquals(listOf(token), api.statusTokens)
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        assertEquals(device, session.state.value.device)
        assertFalse(session.state.value.toString().contains(token))
    }

    @Test fun revokedCredentialIsClearedAndNeverRestored() = runTest {
        val store = Store(token)
        val api = Api().apply { statusFailure = DeviceUnauthorizedException() }
        val session = DeviceSession(store, api)
        session.restore()
        assertNull(store.token)
        assertEquals(1, store.clearCalls)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
        session.restore()
        assertEquals(1, api.statusTokens.size)
    }

    @Test fun offlineRestoreKeepsCredentialForRetryWithoutClaimingConnected() = runTest {
        val store = Store(token)
        val api = Api().apply { statusFailure = IOException("synthetic offline") }
        val session = DeviceSession(store, api)
        session.restore()
        assertEquals(token, store.token)
        assertEquals(DeviceSession.Phase.OFFLINE, session.state.value.phase)
        assertNull(session.state.value.device)
        api.statusFailure = null
        session.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
    }

    @Test fun storageReadFailureNeverCallsBackendAndExplainsParentRevocation() = runTest {
        val store = Store(token).apply { readFailure = true }
        val api = Api()
        val session = DeviceSession(store, api)
        session.restore()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(session.state.value.message.contains("Revoke"))
        session.disconnect()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(api.statusTokens.isEmpty())
        assertTrue(api.disconnectTokens.isEmpty())
    }

    @Test fun disconnectRevokesOnServerBeforeClearingLocalCredentials() = runTest {
        val store = Store(token)
        val api = object : CompanionSessionApi {
            override suspend fun status(credential: String) = device
            override suspend fun disconnect(credential: String) {
                assertEquals(token, credential)
                assertEquals(token, store.token)
                assertEquals(0, store.clearCalls)
            }
        }
        val session = DeviceSession(store, api)
        session.restore()
        session.disconnect()
        assertNull(store.token)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
        assertNull(session.state.value.device)
    }

    @Test fun alreadyRevokedDisconnectStillClearsLocalCredential() = runTest {
        val store = Store(token)
        val api = Api().apply { disconnectFailure = DeviceUnauthorizedException() }
        val session = DeviceSession(store, api)
        session.disconnect()
        assertNull(store.token)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun failedDisconnectRetainsRetryCredentialAndReportsUnconfirmedRevocation() = runTest {
        val store = Store(token)
        val api = Api().apply { disconnectFailure = IOException("synthetic offline") }
        val session = DeviceSession(store, api)
        session.restore()
        session.disconnect()
        assertEquals(token, store.token)
        assertEquals(0, store.clearCalls)
        assertEquals(DeviceSession.Phase.OFFLINE, session.state.value.phase)
        assertTrue(session.state.value.message.contains("unconfirmed"))
        assertNull(session.state.value.device)
        api.disconnectFailure = null
        session.disconnect()
        assertNull(store.token)
    }

    @Test fun localCleanupFailureCannotClaimSuccessfulDisconnectAndCanBeRetried() = runTest {
        val store = Store(token).apply { clearFailure = true }
        val api = Api()
        val session = DeviceSession(store, api)
        session.disconnect()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(session.state.value.message.contains("cleanup failed"))
        assertEquals(token, store.token)
        store.clearFailure = false
        api.disconnectFailure = DeviceUnauthorizedException()
        session.disconnect()
        assertNull(store.token)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun revocationCleanupFailureCannotRestoreConnectedState() = runTest {
        val store = Store(token).apply { clearFailure = true }
        val api = Api().apply { statusFailure = DeviceUnauthorizedException() }
        val session = DeviceSession(store, api)
        session.restore()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertNull(session.state.value.device)
    }

    @Test fun disconnectSerializesBehindPendingRestoreAndEndsUnpaired() = runTest {
        val store = Store(token)
        val api = Api().apply { pendingStatus = CompletableDeferred() }
        val session = DeviceSession(store, api)
        val restoring = launch { session.restore() }
        runCurrent()
        val disconnecting = launch { session.disconnect() }
        runCurrent()
        assertTrue(api.disconnectTokens.isEmpty())
        api.pendingStatus!!.complete(device)
        restoring.join()
        disconnecting.join()
        assertEquals(listOf(token), api.disconnectTokens)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun cancelledRestoreLeavesRetryableStateAndPreservesCredential() = runTest {
        val store = Store(token)
        val api = Api().apply { pendingStatus = CompletableDeferred() }
        val session = DeviceSession(store, api)
        val restoring = launch { session.restore() }
        runCurrent()
        restoring.cancel()
        restoring.join()
        assertEquals(DeviceSession.Phase.OFFLINE, session.state.value.phase)
        assertEquals(token, store.token)
        assertNull(session.state.value.device)
    }
}