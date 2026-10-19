package com.familyguard.child.ui

import com.familyguard.child.data.PairingPreviewApi
import com.familyguard.child.data.PairingRepository
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.*
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class PairingViewModelTest {
    private val dispatcher = StandardTestDispatcher()
    @Before fun setUp() { Dispatchers.setMain(dispatcher) }
    @After fun tearDown() { Dispatchers.resetMain() }

    @Test fun cancelDuringPreviewDoesNotRestoreTheInvitation() = runTest(dispatcher) {
        val response = CompletableDeferred<PairingRepository.Preview>()
        val model = PairingViewModel(object : PairingPreviewApi {
            override suspend fun preview(code: String) = response.await()
        })
        model.updateCode("A".repeat(32))
        model.preview()
        runCurrent()
        assertTrue(model.state.value.busy)
        model.cancel()
        response.complete(PairingRepository.Preview("Synthetic family", "Synthetic child"))
        advanceUntilIdle()
        assertNull(model.state.value.preview)
        assertEquals("", model.state.value.code)
        assertFalse(model.state.value.busy)
        assertFalse(model.state.value.pairedThisSession)
        assertEquals("Pairing cancelled. No approval sent.", model.state.value.message)
    }

    @Test fun editingCodeInvalidatesAnInFlightPreview() = runTest(dispatcher) {
        val response = CompletableDeferred<PairingRepository.Preview>()
        val model = PairingViewModel(object : PairingPreviewApi {
            override suspend fun preview(code: String) = response.await()
        })
        model.updateCode("A".repeat(32))
        model.preview()
        runCurrent()
        model.updateCode("B".repeat(32))
        response.complete(PairingRepository.Preview("Old family", "Old child"))
        advanceUntilIdle()
        assertNull(model.state.value.preview)
        assertEquals("B".repeat(32), model.state.value.code)
    }

    @Test fun previewAndApprovalGateNeverClaimSuccessfulPairing() = runTest(dispatcher) {
        val model = PairingViewModel(object : PairingPreviewApi {
            override suspend fun preview(code: String) = PairingRepository.Preview("Family", "Child")
        })
        model.updateCode("A".repeat(32))
        model.preview()
        advanceUntilIdle()
        assertEquals("Child", model.state.value.preview?.childName)
        model.approve()
        assertFalse(model.state.value.pairedThisSession)
        assertTrue(model.state.value.message.contains("Secure device credential storage"))
    }
    private fun readyModel() = PairingViewModel(object : PairingPreviewApi {
        override suspend fun preview(code: String) = PairingRepository.Preview("Family", "Child")
    })

    @Test fun approvalRequiresPreviewAndCancellationSendsNoApproval() = runTest(dispatcher) {
        val model = readyModel()
        var calls = 0
        val action: suspend (String) -> com.familyguard.child.data.DeviceSession.ApprovalResult = {
            calls++; com.familyguard.child.data.DeviceSession.ApprovalResult.VERIFIED
        }
        model.updateCode("A".repeat(32))
        model.approve(action)
        advanceUntilIdle()
        assertEquals(0, calls)
        model.preview()
        advanceUntilIdle()
        model.cancel()
        model.approve(action)
        advanceUntilIdle()
        assertEquals(0, calls)
    }

    @Test fun codeChangeInvalidatesPreviewBeforeApproval() = runTest(dispatcher) {
        val model = readyModel()
        var calls = 0
        model.updateCode("A".repeat(32))
        model.preview()
        advanceUntilIdle()
        model.updateCode("B".repeat(32))
        model.approve { calls++; com.familyguard.child.data.DeviceSession.ApprovalResult.VERIFIED }
        advanceUntilIdle()
        assertEquals(0, calls)
    }

    @Test fun explicitApprovalCannotBeDuplicatedOrCancelledAfterSending() = runTest(dispatcher) {
        val model = readyModel()
        val response = CompletableDeferred<com.familyguard.child.data.DeviceSession.ApprovalResult>()
        var calls = 0
        val action: suspend (String) -> com.familyguard.child.data.DeviceSession.ApprovalResult = {
            assertEquals("A".repeat(32), it)
            calls++; response.await()
        }
        model.updateCode("A".repeat(32))
        model.preview()
        advanceUntilIdle()
        model.approve(action)
        runCurrent()
        model.cancel()
        model.updateCode("B".repeat(32))
        model.approve(action)
        assertTrue(model.state.value.approving)
        assertEquals("A".repeat(32), model.state.value.code)
        response.complete(com.familyguard.child.data.DeviceSession.ApprovalResult.VERIFIED)
        advanceUntilIdle()
        assertEquals(1, calls)
        assertTrue(model.state.value.pairedThisSession)
        assertEquals("", model.state.value.code)
        assertNull(model.state.value.preview)
    }

    @Test fun failedPreviewDoesNotPermitApproval() = runTest(dispatcher) {
        val model = PairingViewModel(object : PairingPreviewApi {
            override suspend fun preview(code: String): PairingRepository.Preview = error("expired synthetic invitation")
        })
        var calls = 0
        model.updateCode("A".repeat(32))
        model.preview()
        advanceUntilIdle()
        model.approve { calls++; com.familyguard.child.data.DeviceSession.ApprovalResult.VERIFIED }
        advanceUntilIdle()
        assertEquals(0, calls)
        assertNull(model.state.value.preview)
        assertFalse(model.state.value.pairedThisSession)
    }
}
