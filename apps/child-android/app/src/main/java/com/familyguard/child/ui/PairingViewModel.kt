package com.familyguard.child.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.familyguard.child.data.DeviceSession
import com.familyguard.child.data.PairingRepository
import com.familyguard.child.data.PairingPreviewApi
import kotlinx.coroutines.Job
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** Preview and affirmative approval only; an in-flight approval must finish persistence/recovery. */
class PairingViewModel(private val api: PairingPreviewApi = PairingRepository()) : ViewModel() {
    private var previewJob: Job? = null
    private var requestVersion = 0
    private val _state = MutableStateFlow(State())
    val state = _state.asStateFlow()

    data class State(
        val code: String = "",
        val preview: PairingRepository.Preview? = null,
        val busy: Boolean = false,
        val approving: Boolean = false,
        val message: String = "",
        val pairedThisSession: Boolean = false
    )

    fun updateCode(input: String) {
        if (state.value.approving) return
        requestVersion++
        previewJob?.cancel()
        _state.value = State(code = input.uppercase().filter { it in "0123456789ABCDEF" }.take(32))
    }

    fun preview() {
        val code = state.value.code
        if (code.length != 32 || state.value.busy) return
        _state.value = state.value.copy(busy = true, preview = null, message = "")
        val version = ++requestVersion
        previewJob = viewModelScope.launch {
            try {
                val preview = api.preview(code)
                if (version == requestVersion) {
                    _state.value = state.value.copy(preview = preview, busy = false)
                }
            } catch (e: CancellationException) { throw e }
            catch (e: Exception) {
                if (version == requestVersion) {
                    _state.value = state.value.copy(busy = false, message = "Invitation invalid, expired, or server unavailable.")
                }
            }
        }
    }

    fun cancel() {
        if (state.value.approving) return
        requestVersion++
        previewJob?.cancel()
        _state.value = State(message = "Pairing cancelled. No approval sent.")
    }

    fun approve(action: (suspend (String) -> DeviceSession.ApprovalResult)? = null) {
        if (state.value.busy || state.value.preview == null || state.value.code.length != 32) return
        if (action == null) {
            _state.value = state.value.copy(message = "Secure device credential storage must be connected before approval.")
            return
        }
        val code = state.value.code
        _state.value = state.value.copy(busy = true, approving = true, message = "Finishing approval and secure storage. Please wait.")
        viewModelScope.launch {
            try {
                val result = action(code)
                _state.value = State(
                    pairedThisSession = result == DeviceSession.ApprovalResult.VERIFIED,
                    message = when (result) {
                        DeviceSession.ApprovalResult.VERIFIED -> "Pairing saved and verified. No monitoring is active."
                        DeviceSession.ApprovalResult.SAVED_UNVERIFIED -> "Pairing saved. Open Connected Device Home to retry verification."
                        DeviceSession.ApprovalResult.RECOVERY_REQUIRED -> "Recovery required. Open Connected Device Home and retry disconnect, or revoke from the parent account."
                        DeviceSession.ApprovalResult.FAILED -> "Pairing did not complete. Check device status and the parent device list before retrying."
                    }
                )
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (failure: Exception) {
                _state.value = State(message = "Approval is unconfirmed. Check the parent device list and device status before retrying.")
            }
        }
    }
}