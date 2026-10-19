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
class DeviceRotationTest {
    private val old = "a".repeat(64)
    private val next = "b".repeat(64)
    private val device = CompanionDevice("synthetic-id", "Synthetic phone")

    private class Store(var journal: CredentialJournal?) : RotatingDeviceCredentialStore {
        var stageFailure = false
        var loseStage = false
        var promoteFailure = false
        var failAfterPromotion = false
        var clearFailure = false
        var stages = 0
        override suspend fun prepare() { }
        override suspend fun save(token: String) { journal = CredentialJournal(token) }
        override suspend fun read() = journal?.current
        override suspend fun readJournal() = journal
        override suspend fun stage(current: String, pending: String) {
            stages++
            if (stageFailure) throw IOException("synthetic stage failure")
            check(journal?.current == current)
            if (!loseStage) journal = CredentialJournal(current, pending)
        }
        override suspend fun promote(current: String, pending: String) {
            if (promoteFailure) throw IOException("synthetic promotion failure")
            check(journal?.current == current && journal?.pending == pending)
            journal = CredentialJournal(pending)
            if (failAfterPromotion) throw IOException("synthetic read-back failure")
        }
        override suspend fun clear() {
            if (clearFailure) throw IOException("synthetic cleanup failure")
            journal = null
        }
    }

    private inner class Api(private val store: Store) : CompanionSessionApi, CompanionRotationApi {
        var active: String? = old
        var rotations = 0
        var offlineStatus = false
        var offlineDisconnect = false
        var failBeforeCommit = false
        var loseResponse = false
        var commitBeforeOldDisconnect = false
        var gate: CompletableDeferred<Unit>? = null
        val disconnects = mutableListOf<String>()
        override suspend fun status(credential: String): CompanionDevice {
            if (offlineStatus) throw IOException("synthetic offline")
            if (credential != active) throw DeviceUnauthorizedException()
            return device
        }
        override suspend fun rotate(currentCredential: String, nextCredentialHash: String) {
            rotations++
            check(store.journal?.pending == next) // Durable staging precedes the request.
            assertEquals(CredentialTokens.hash(next), nextCredentialHash)
            assertNotEquals(next, nextCredentialHash)
            if (failBeforeCommit) throw IOException("synthetic pre-commit failure")
            if (currentCredential != active) throw DeviceUnauthorizedException()
            active = next
            gate?.await()
            if (loseResponse) throw IOException("synthetic response loss after commit")
        }
        override suspend fun disconnect(credential: String) {
            disconnects.add(credential)
            if (offlineDisconnect) throw IOException("synthetic offline")
            if (commitBeforeOldDisconnect && credential == old) {
                active = next
                commitBeforeOldDisconnect = false
            }
            if (credential != active) throw DeviceUnauthorizedException()
            active = null
        }
    }

    private fun coordinator(store: Store, api: Api) = DeviceRotation(store, api, api) { next }

    @Test fun stagesBeforeRequestAndPromotesOnlyAfterServerValidation() = runTest {
        val store = Store(CredentialJournal(old))
        val api = Api(store)
        val result = coordinator(store, api).rotate()
        assertEquals(DeviceSession.Phase.CONNECTED, result.phase)
        assertEquals(next, store.journal?.current)
        assertNull(store.journal?.pending)
        assertEquals(1, store.stages)
        assertEquals(1, api.rotations)
        assertFalse(result.toString().contains(next))
    }

    @Test fun failedOrLostStagingNeverContactsRotationEndpoint() = runTest {
        for (loseWrite in listOf(false, true)) {
            val store = Store(CredentialJournal(old)).apply { stageFailure = !loseWrite; loseStage = loseWrite }
            val api = Api(store)
            assertEquals(DeviceSession.Phase.ERROR, coordinator(store, api).rotate().phase)
            assertEquals(0, api.rotations)
            assertEquals(old, api.active)
        }
    }

    @Test fun lostResponseAfterCommitRecoversUsingPendingCredential() = runTest {
        val store = Store(CredentialJournal(old))
        val api = Api(store).apply { loseResponse = true }
        assertEquals(DeviceSession.Phase.CONNECTED, coordinator(store, api).rotate().phase)
        assertEquals(next, store.journal?.current)
        assertNull(store.journal?.pending)
    }

    @Test fun preCommitFailurePreservesCandidateAndRetriesTheSameGeneration() = runTest {
        val store = Store(CredentialJournal(old))
        val api = Api(store).apply { failBeforeCommit = true }
        assertEquals(DeviceSession.Phase.ERROR, coordinator(store, api).rotate().phase)
        assertEquals(old, store.journal?.current)
        assertEquals(next, store.journal?.pending)
        api.failBeforeCommit = false
        val restarted = DeviceRotation(store, api, api) { error("Must not generate another candidate") }
        assertEquals(DeviceSession.Phase.CONNECTED, restarted.rotate().phase)
        assertEquals(1, store.stages)
        assertEquals(2, api.rotations)
    }

    @Test fun restartAfterServerCommitPromotesWithoutAnotherRotationRequest() = runTest {
        val store = Store(CredentialJournal(old, next))
        val api = Api(store).apply { active = next }
        val session = DeviceSession(store, api)
        session.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        assertEquals(next, store.journal?.current)
        assertEquals(0, api.rotations)
    }

    @Test fun restartBeforeRequestDoesNotSilentlyRotateOrForgetPendingState() = runTest {
        val store = Store(CredentialJournal(old, next))
        val api = Api(store)
        val session = DeviceSession(store, api)
        session.restore()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertEquals(next, store.journal?.pending)
        assertEquals(0, api.rotations)
    }

    @Test fun revokedOrAmbiguousBoth401RetainsRecoveryJournal() = runTest {
        val store = Store(CredentialJournal(old, next))
        val api = Api(store).apply { active = null }
        assertEquals(DeviceSession.Phase.ERROR, coordinator(store, api).recover()?.phase)
        assertEquals(old, store.journal?.current)
        assertEquals(next, store.journal?.pending)
    }

    @Test fun offlineRecoveryNeverClaimsConnectionOrDeletesTheJournal() = runTest {
        val store = Store(CredentialJournal(old, next))
        val api = Api(store).apply { offlineStatus = true }
        val result = coordinator(store, api).recover()
        assertEquals(DeviceSession.Phase.ERROR, result?.phase)
        assertNull(result?.device)
        assertEquals(next, store.journal?.pending)
    }

    @Test fun failedPromotionCanRecoverAfterRestart() = runTest {
        val store = Store(CredentialJournal(old, next)).apply { promoteFailure = true }
        val api = Api(store).apply { active = next }
        assertEquals(DeviceSession.Phase.ERROR, coordinator(store, api).recover()?.phase)
        assertEquals(old, store.journal?.current)
        store.promoteFailure = false
        assertEquals(DeviceSession.Phase.CONNECTED, coordinator(store, api).recover()?.phase)
        assertEquals(next, store.journal?.current)
    }

    @Test fun promotionCommittedButReadbackFailedRestoresTheNewSingleGeneration() = runTest {
        val store = Store(CredentialJournal(old, next)).apply { failAfterPromotion = true }
        val api = Api(store).apply { active = next }
        assertEquals(DeviceSession.Phase.ERROR, coordinator(store, api).recover()?.phase)
        val restarted = DeviceSession(store, api)
        restarted.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, restarted.state.value.phase)
        assertEquals(next, store.journal?.current)
    }

    @Test fun disconnectResolvesBothUncommittedAndCommittedGenerations() = runTest {
        for (activeGeneration in listOf(old, next)) {
            val store = Store(CredentialJournal(old, next))
            val api = Api(store).apply { active = activeGeneration }
            val session = DeviceSession(store, api)
            session.disconnect()
            assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
            assertNull(api.active)
            assertNull(store.journal)
        }
    }

    @Test fun disconnectRetriesPendingWhenCommitOccursBetweenCredentialAttempts() = runTest {
        val store = Store(CredentialJournal(old, next))
        val api = Api(store).apply { commitBeforeOldDisconnect = true }
        assertEquals(DeviceSession.Phase.UNPAIRED, coordinator(store, api).disconnectPending()?.phase)
        assertEquals(listOf(next, old, next), api.disconnects)
        assertNull(store.journal)
    }

    @Test fun unconfirmedDisconnectAndAllRejectedCredentialsKeepTheJournal() = runTest {
        for (offline in listOf(false, true)) {
            val store = Store(CredentialJournal(old, next))
            val api = Api(store).apply { offlineDisconnect = offline; active = null }
            assertEquals(DeviceSession.Phase.ERROR, coordinator(store, api).disconnectPending()?.phase)
            assertNotNull(store.journal)
        }
    }

    @Test fun cleanupFailureCannotClaimSuccessfulDisconnect() = runTest {
        val store = Store(CredentialJournal(old, next)).apply { clearFailure = true }
        val api = Api(store).apply { active = next }
        assertEquals(DeviceSession.Phase.ERROR, coordinator(store, api).disconnectPending()?.phase)
        assertNotNull(store.journal)
    }

    @Test fun cancellationAfterCommitRetainsJournalForRestartRecovery() = runTest {
        val store = Store(CredentialJournal(old))
        val api = Api(store).apply { gate = CompletableDeferred() }
        val session = DeviceSession(store, api, generateRotationCredential = { next })
        val operation = launch { session.rotate() }
        runCurrent()
        operation.cancel()
        operation.join()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertNotNull(store.journal?.pending)
        val restarted = DeviceSession(store, api)
        restarted.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, restarted.state.value.phase)
        assertNull(store.journal?.pending)
    }
    @Test fun missingCredentialDoesNotStageOrCallRotation() = runTest {
        val store = Store(null)
        val api = Api(store)
        assertEquals(DeviceSession.Phase.UNPAIRED, coordinator(store, api).rotate().phase)
        assertEquals(0, api.rotations)
        assertEquals(0, store.stages)
    }

    @Test fun revocationBeforeStagingCannotCreateAReplacement() = runTest {
        val store = Store(CredentialJournal(old))
        val api = Api(store).apply { active = null }
        assertEquals(DeviceSession.Phase.UNPAIRED, coordinator(store, api).rotate().phase)
        assertEquals(0, api.rotations)
        assertNull(store.journal)
    }

    @Test fun cancellationBeforeDispatchDoesNotChangeTheSession() = runTest {
        val store = Store(CredentialJournal(old))
        val api = Api(store)
        val session = DeviceSession(store, api, generateRotationCredential = { next })
        val operation = launch { session.rotate() }
        operation.cancel()
        operation.join()
        assertEquals(0, api.rotations)
        assertNull(store.journal?.pending)
        assertEquals(old, store.journal?.current)
    }}