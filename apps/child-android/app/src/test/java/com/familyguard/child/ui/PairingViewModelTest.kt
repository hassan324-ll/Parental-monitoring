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
}
