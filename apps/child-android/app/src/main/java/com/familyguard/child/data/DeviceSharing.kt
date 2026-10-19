package com.familyguard.child.data

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

enum class SharingFeature(val wire: String) { USAGE("usage"), LOCATION("location") }
data class SharingConsent(val enabled: Boolean, val version: Long, val retention: String)
data class SharingSnapshot(val usage: SharingConsent, val location: SharingConsent) {
    fun consent(feature: SharingFeature) = if (feature == SharingFeature.USAGE) usage else location
}
interface DeviceSharingApi {
    suspend fun sharingStatus(credential: String): SharingSnapshot
    suspend fun saveSharingConsent(credential: String, feature: SharingFeature): SharingSnapshot
    suspend fun stopSharing(credential: String, feature: SharingFeature): SharingSnapshot
}
data class SharingState(
    val phase: Phase = Phase.IDLE,
    val snapshot: SharingSnapshot? = null,
    val revision: Long = 0,
    val message: String = "Verify device authorization before checking sharing preferences."
) {
    enum class Phase { IDLE, LOADING, READY, UPDATING, ERROR }
    val busy get() = phase == Phase.LOADING || phase == Phase.UPDATING
}

/** All calls run under DeviceSession's operation mutex, including invalidation on identity changes. */
internal class SharingControls(private val credentials: DeviceCredentialStore, private val api: DeviceSharingApi?) {
    private val mutableState = MutableStateFlow(SharingState())
    val state = mutableState.asStateFlow()
    fun invalidate(message: String = "Verify sharing preferences again. No collection is active.") {
        publish(SharingState.Phase.IDLE, message = message)
    }
    private fun publish(phase: SharingState.Phase, snapshot: SharingSnapshot? = null, message: String) {
        mutableState.value = SharingState(phase, snapshot, state.value.revision + 1, message)
    }
    suspend fun refresh(authorized: Boolean): Boolean {
        if (!authorized || api == null) { invalidate(); return false }
        return request(SharingState.Phase.LOADING) { token -> api.sharingStatus(token) }
    }
    suspend fun decide(authorized: Boolean, feature: SharingFeature, enabled: Boolean, acknowledged: Boolean, revision: Long): Boolean {
        if (!authorized || api == null || (enabled && !acknowledged)) return false
        if (revision != state.value.revision || (enabled && state.value.phase != SharingState.Phase.READY)) {
            invalidate("Confirmation expired. Refresh and review your choice again.")
            return false
        }
        val previous = state.value.snapshot?.consent(feature)
        return request(SharingState.Phase.UPDATING) { token ->
            val result = if (enabled) api.saveSharingConsent(token, feature) else api.stopSharing(token, feature)
            check(result.consent(feature).enabled == enabled) { "Sharing decision is unconfirmed." }
            if (previous != null) check(result.consent(feature).version > previous.version) { "Sharing response is stale." }
            result
        }
    }
    private suspend fun request(phase: SharingState.Phase, action: suspend (String) -> SharingSnapshot): Boolean {
        publish(phase, message = if (phase == SharingState.Phase.LOADING) "Checking saved preferences…" else "Saving your choice. Server confirmation is required…")
        try {
            val token = credentials.read()
            if (token == null) {
                publish(SharingState.Phase.ERROR, message = "Local credential unavailable. Verify device authorization again.")
                return true
            }
            val snapshot = action(token)
            publish(SharingState.Phase.READY, snapshot, "Preferences verified. Collection is unavailable in this version; nothing is collected.")
        } catch (cancelled: CancellationException) {
            publish(SharingState.Phase.ERROR, message = "Sharing request interrupted. The server result is unknown; refresh before trying again.")
            throw cancelled
        } catch (revoked: DeviceUnauthorizedException) {
            publish(SharingState.Phase.ERROR, message = "Device authorization rejected. Verify the device session; saved consent and deletion status are unknown.")
            return true
        } catch (failure: Exception) {
            publish(SharingState.Phase.ERROR, message = "Cannot confirm sharing preferences or deletion. Refresh to verify; no collection is active in this version.")
        }
        return false
    }
}
