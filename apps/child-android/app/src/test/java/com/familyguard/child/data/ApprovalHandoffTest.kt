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
class ApprovalHandoffTest {
    private val token = "c".repeat(64)
    private val code = "A".repeat(32)
    private class Store : ApprovingDeviceCredentialStore {
        var encoded: String? = null
        var failStage = false
        var loseStage = false
        var failConfirm = false
        var failClear = false
        var stages = 0
        override suspend fun prepare() { }
        override suspend fun readJournal() = encoded?.let(CredentialJournalCodec::decode)
        override suspend fun read() = readJournal()?.current
        override suspend fun save(token: String) { encoded = CredentialJournalCodec.encode(CredentialJournal(token)) }
        override suspend fun stageApproval(credential: String) {
            check(encoded == null)
            stages++
            if (failStage) throw IOException("synthetic stage failure")
            if (!loseStage) encoded = CredentialJournalCodec.encode(CredentialJournal(credential, approvalPending = true))
        }
        override suspend fun confirmApproval(credential: String) {
            check(readJournal()?.current == credential && readJournal()?.approvalPending == true)
            if (failConfirm) throw IOException("synthetic confirmation failure")
            save(credential)
        }
        override suspend fun stage(current: String, pending: String) { error("Not an approval operation") }
        override suspend fun promote(current: String, pending: String) { error("Not an approval operation") }
        override suspend fun clear() {
            if (failClear) throw IOException("synthetic clear failure")
            encoded = null
        }
    }
    private inner class Api(private val store: Store) : CompanionSessionApi, CompanionRotationApi, PairingApprovalApi, StagedPairingApprovalApi {
        var approvals = 0
        var rotations = 0
        var active = false
        var offline = false
        var failBeforeCommit = false
        var loseResponse = false
        var gate: CompletableDeferred<Unit>? = null
        override suspend fun approve(code: String, deviceName: String, androidVersion: String): PairingRepository.Approval = error("Legacy approval must not run")
        override suspend fun approveStaged(code: String, deviceName: String, androidVersion: String, credentialHash: String) {
            approvals++
            assertEquals(this@ApprovalHandoffTest.code, code)
            assertEquals(token, store.readJournal()?.current)
            assertTrue(store.readJournal()!!.approvalPending)
            assertEquals(CredentialTokens.hash(token), credentialHash)
            assertNotEquals(token, credentialHash)
            if (failBeforeCommit) throw IOException("synthetic precommit loss")
            gate?.await()
            active = true
            if (loseResponse) throw IOException("synthetic response loss")
        }
        override suspend fun status(credential: String): CompanionDevice {
            assertEquals(token, credential)
            if (offline) throw IOException("synthetic offline")
            if (!active) throw DeviceUnauthorizedException()
            return CompanionDevice("synthetic-id", "Synthetic phone")
        }
        override suspend fun disconnect(credential: String) {
            assertEquals(token, credential)
            if (offline) throw IOException("synthetic offline")
            if (!active) throw DeviceUnauthorizedException()
            active = false
        }
        override suspend fun rotate(currentCredential: String, nextCredentialHash: String) { rotations++ }
    }
    private fun session(store: Store, api: Api) = DeviceSession(store, api, api, generateApprovalCredential = { token })

    @Test fun stagesAndVerifiesBeforeHashRequestThenPromotesAfterAuthorization() = runTest {
        val store = Store(); val api = Api(store); val session = session(store, api)
        assertEquals(DeviceSession.ApprovalResult.VERIFIED, session.approve(code, "Phone", "26"))
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        assertFalse(store.readJournal()!!.approvalPending)
        assertEquals(1, store.stages)
        assertEquals(1, api.approvals)
        assertFalse(session.state.value.toString().contains(token))
    }

    @Test fun stageFailuresAndLostReadbackNeverSendApproval() = runTest {
        for (lost in listOf(false, true)) {
            val store = Store().apply { loseStage = lost; failStage = !lost }
            val api = Api(store); val session = session(store, api)
            assertEquals(DeviceSession.ApprovalResult.RECOVERY_REQUIRED, session.approve(code, "Phone", "26"))
            assertEquals(0, api.approvals)
            assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        }
    }

    @Test fun lostResponseAfterCommitUsesStagedCredentialWithoutReplay() = runTest {
        val store = Store(); val api = Api(store).apply { loseResponse = true }
        val session = session(store, api)
        assertEquals(DeviceSession.ApprovalResult.VERIFIED, session.approve(code, "Phone", "26"))
        session(store, api).restore()
        assertEquals(1, api.approvals)
        assertFalse(store.readJournal()!!.approvalPending)
    }

    @Test fun uncertain401SurvivesReconstructionAndLaterCommitWithoutReapproval() = runTest {
        val store = Store(); val api = Api(store).apply { failBeforeCommit = true }
        val first = session(store, api)
        assertEquals(DeviceSession.ApprovalResult.RECOVERY_REQUIRED, first.approve(code, "Phone", "26"))
        assertTrue(store.readJournal()!!.approvalPending)
        val restarted = session(store, api)
        restarted.restore()
        assertEquals(DeviceSession.Phase.ERROR, restarted.state.value.phase)
        assertTrue(store.readJournal()!!.approvalPending)
        api.active = true // Models a server commit later than the client's first status check.
        restarted.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, restarted.state.value.phase)
        assertEquals(1, api.approvals)
        assertEquals(1, store.stages)
    }

    @Test fun offlineRestorationRetainsPendingApprovalAndDoesNotResend() = runTest {
        val store = Store(); val api = Api(store).apply { offline = true }
        val first = session(store, api)
        assertEquals(DeviceSession.ApprovalResult.SAVED_UNVERIFIED, first.approve(code, "Phone", "26"))
        val restarted = session(store, api)
        restarted.restore()
        assertEquals(DeviceSession.Phase.OFFLINE, restarted.state.value.phase)
        assertTrue(store.readJournal()!!.approvalPending)
        api.offline = false
        restarted.restore()
        assertEquals(DeviceSession.Phase.CONNECTED, restarted.state.value.phase)
        assertEquals(1, api.approvals)
    }

    @Test fun rejectedPendingDisconnectDoesNotClaimRevocationOrErasePotentiallyInflightApproval() = runTest {
        val store = Store(); val api = Api(store).apply { failBeforeCommit = true }
        val session = session(store, api)
        session.approve(code, "Phone", "26")
        session.disconnect()
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(store.readJournal()!!.approvalPending)
        api.active = true
        session.disconnect()
        assertNull(store.readJournal())
        assertFalse(api.active)
        assertEquals(DeviceSession.Phase.UNPAIRED, session.state.value.phase)
    }

    @Test fun confirmationFailureNeverPublishesConnectedAndCanBeRecovered() = runTest {
        val store = Store().apply { failConfirm = true }; val api = Api(store)
        val session = session(store, api)
        assertEquals(DeviceSession.ApprovalResult.RECOVERY_REQUIRED, session.approve(code, "Phone", "26"))
        assertNull(session.state.value.device)
        assertTrue(store.readJournal()!!.approvalPending)
        store.failConfirm = false
        session(store, api).also { it.restore(); assertEquals(DeviceSession.Phase.CONNECTED, it.state.value.phase) }
        assertEquals(1, api.approvals)
    }

    @Test fun pendingApprovalCannotBeOverwrittenOrRotatedOrSilentlyCleared() = runTest {
        val store = Store(); val api = Api(store).apply { failBeforeCommit = true }
        val session = session(store, api)
        session.approve(code, "Phone", "26")
        val retained = store.encoded
        assertEquals(DeviceSession.ApprovalResult.FAILED, session.approve(code, "Phone", "26"))
        session.rotate()
        assertEquals(0, api.rotations)
        assertEquals(1, api.approvals)
        assertEquals(retained, store.encoded)
        session.forgetLocalPairing(false)
        assertEquals(retained, store.encoded)
        session.forgetLocalPairing(true)
        assertNull(store.encoded)
    }

    @Test fun callerCancellationAfterSendFinishesStagingAndVerification() = runTest {
        val store = Store(); val api = Api(store).apply { gate = CompletableDeferred() }
        val session = session(store, api)
        val job = launch { session.approve(code, "Phone", "26") }
        runCurrent()
        assertTrue(store.readJournal()!!.approvalPending)
        job.cancel()
        api.gate!!.complete(Unit)
        job.join()
        assertEquals(DeviceSession.Phase.CONNECTED, session.state.value.phase)
        assertEquals(1, api.approvals)
    }

    @Test fun cancellationBeforeExecutionNeverStagesOrApproves() = runTest {
        val store = Store(); val api = Api(store); val session = session(store, api)
        val job = launch { session.approve(code, "Phone", "26") }
        job.cancel(); job.join()
        assertNull(store.encoded)
        assertEquals(0, api.approvals)
    }

    @Test fun failedCleanupAfterServerDisconnectKeepsRecoveryVisible() = runTest {
        val store = Store().apply { failConfirm = true; failClear = true }; val api = Api(store)
        val session = session(store, api)
        session.approve(code, "Phone", "26")
        session.disconnect()
        assertFalse(api.active)
        assertEquals(DeviceSession.Phase.ERROR, session.state.value.phase)
        assertTrue(store.readJournal()!!.approvalPending)
        store.failClear = false
        session.forgetLocalPairing(true)
        assertNull(store.encoded)
    }

    @Test fun approvalMarkerRoundTripsAndRejectsRotationOverlap() {
        val pending = CredentialJournal(token, approvalPending = true)
        val restored = CredentialJournalCodec.decode(CredentialJournalCodec.encode(pending))
        assertTrue(restored.approvalPending)
        assertEquals(token, restored.current)
        assertFalse(restored.toString().contains(token))
        assertFalse(CredentialJournalCodec.decode(token).approvalPending)
        assertThrows(IllegalArgumentException::class.java) { CredentialJournal(token, "b".repeat(64), true) }
        assertThrows(IllegalArgumentException::class.java) { CredentialJournalCodec.decode("FGA1\n$token\nbad") }
    }
}
