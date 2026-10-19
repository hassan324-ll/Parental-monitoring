package com.familyguard.child.data

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.withContext
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/** No credential enters observable UI state. All restored sessions require server validation. */
class DeviceSession(
    private val credentials: DeviceCredentialStore,
    private val api: CompanionSessionApi,
    private val pairing: PairingApprovalApi? = null
) {
    enum class Phase { UNPAIRED, CHECKING, CONNECTED, OFFLINE, DISCONNECTING, PAIRING, ERROR }
    data class State(
        val phase: Phase = Phase.UNPAIRED,
        val device: CompanionDevice? = null,
        val message: String = "No device session has been verified."
    )
    enum class ApprovalResult { VERIFIED, SAVED_UNVERIFIED, FAILED, RECOVERY_REQUIRED }
    private var pendingRevocation: String? = null
    private val lock = Mutex()
    private val mutableState = MutableStateFlow(State())
    val state = mutableState.asStateFlow()

    suspend fun restore() = lock.withLock {
        if (pendingRevocation != null) {
            mutableState.value = State(Phase.ERROR, message = "Pairing recovery required. Retry disconnect or revoke from the parent account.")
            return@withLock
        }
        mutableState.value = State(Phase.CHECKING, message = "Checking device authorization…")
        try {
            val token = readCredential() ?: return@withLock
            try {
                val device = api.status(token)
                mutableState.value = State(Phase.CONNECTED, device, "Device authorization verified. No monitoring is active.")
            } catch (failure: DeviceUnauthorizedException) {
                clearRejectedCredential("Device authorization ended. Pair again to reconnect.")
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                mutableState.value = State(Phase.OFFLINE, message = "Cannot verify device authorization. Check the connection and retry.")
            }
        } catch (cancelled: CancellationException) {
            mutableState.value = State(Phase.OFFLINE, message = "Authorization check interrupted. Retry to verify this device.")
            throw cancelled
        }
    }

    suspend fun disconnect() = lock.withLock {
        mutableState.value = State(Phase.DISCONNECTING, message = "Disconnecting device…")
        try {
            val token = pendingRevocation ?: readCredential() ?: return@withLock
            try {
                api.disconnect(token)
            } catch (failure: DeviceUnauthorizedException) {
                // Already revoked is a successful disconnect; local cleanup is still required.
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (failure: Exception) {
                mutableState.value = State(Phase.OFFLINE, message = "Server disconnect is unconfirmed. Retry, or revoke this device from the parent account.")
                return@withLock
            }
            clearRejectedCredential("Device disconnected. Local credentials removed.")
        } catch (cancelled: CancellationException) {
            mutableState.value = State(Phase.OFFLINE, message = "Disconnect interrupted. Server status is unknown; retry or revoke from the parent account.")
            throw cancelled
        }
    }

    /** Called only after preview and an affirmative tap. Finish persistence/compensation if UI leaves. */
    suspend fun approve(code: String, deviceName: String, androidVersion: String): ApprovalResult = lock.withLock {
        currentCoroutineContext().ensureActive()
        withContext(NonCancellable) {
            if (pairing == null || !code.matches(Regex("[A-F0-9]{32}"))) {
                return@withContext ApprovalResult.FAILED
            }
            try {
                check(pendingRevocation == null && credentials.read() == null)
                credentials.prepare()
            } catch (failure: Exception) {
                mutableState.value = State(Phase.ERROR, message = "Cannot start pairing. Disconnect any existing session and check secure storage first.")
                return@withContext ApprovalResult.FAILED
            }
            mutableState.value = State(Phase.PAIRING, message = "Approving pairing and saving this device securely…")
            val approval = try {
                pairing.approve(code, deviceName, androidVersion)
            } catch (failure: Exception) {
                mutableState.value = State(Phase.ERROR, message = "Approval failed or is unconfirmed. Check the parent device list before creating a new invitation.")
                return@withContext ApprovalResult.FAILED
            }
            val token = approval.deviceToken
            pendingRevocation = token
            try {
                check(token.matches(Regex("[a-f0-9]{64}")))
                credentials.save(token)
                check(credentials.read() == token)
            } catch (failure: Exception) {
                try {
                    try { api.disconnect(token) } catch (revoked: DeviceUnauthorizedException) { }
                    credentials.clear()
                    pendingRevocation = null
                    mutableState.value = State(message = "Pairing was revoked because secure storage failed. Create a new invitation to retry.")
                    return@withContext ApprovalResult.FAILED
                } catch (cleanup: Exception) {
                    mutableState.value = State(Phase.ERROR, message = "Pairing recovery is required. Retry disconnect now, or revoke this device from the parent account before leaving or re-pairing.")
                    return@withContext ApprovalResult.RECOVERY_REQUIRED
                }
            }
            pendingRevocation = null
            try {
                val device = api.status(token)
                mutableState.value = State(Phase.CONNECTED, device, "Pairing saved and device authorization verified. No monitoring is active.")
                ApprovalResult.VERIFIED
            } catch (revoked: DeviceUnauthorizedException) {
                clearRejectedCredential("Device authorization ended. Create a new invitation to reconnect.")
                ApprovalResult.FAILED
            } catch (failure: Exception) {
                mutableState.value = State(Phase.OFFLINE, message = "Pairing saved securely; authorization verification is unavailable. Retry verification.")
                ApprovalResult.SAVED_UNVERIFIED
            }
        }
    }
    private suspend fun readCredential(): String? {
        return try {
            val token = credentials.read()
            if (token == null) mutableState.value = State(message = "This device is not paired.")
            token
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            mutableState.value = State(Phase.ERROR, message = "Stored credentials unavailable. Revoke this device from the parent account before re-pairing.")
            null
        }
    }

    private suspend fun clearRejectedCredential(message: String) {
        try {
            credentials.clear()
            pendingRevocation = null
            mutableState.value = State(message = message)
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (failure: Exception) {
            mutableState.value = State(Phase.ERROR, message = "Server access ended, but local credential cleanup failed. Retry disconnect.")
        }
    }
}