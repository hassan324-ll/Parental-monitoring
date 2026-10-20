package com.familyguard.child.data

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

enum class PermissionReadiness { GRANTED, NOT_GRANTED, UNKNOWN }
fun interface SharingPermissionReader { fun inspect(feature: SharingFeature): PermissionReadiness }

data class SharingReviewState(
    val phase: Phase = Phase.CLOSED,
    val preferenceVersion: Long? = null,
    val acknowledged: Boolean = false,
    val permission: PermissionReadiness? = null
) {
    enum class Phase { CLOSED, DISCLOSURE, RESULT }
}

/** Read-only rehearsal. It cannot submit consent, request permission or start a collector. */
class SharingPermissionReview(private val feature: SharingFeature, private val permissions: SharingPermissionReader) {
    private val mutableState = MutableStateFlow(SharingReviewState())
    val state = mutableState.asStateFlow()
    fun open(authorized: Boolean, savedVersion: Long?) {
        cancel()
        if (authorized && savedVersion != null && savedVersion > 0) {
            mutableState.value = SharingReviewState(SharingReviewState.Phase.DISCLOSURE, savedVersion)
        }
    }
    fun acknowledge(value: Boolean) {
        if (state.value.phase == SharingReviewState.Phase.DISCLOSURE) mutableState.value = state.value.copy(acknowledged = value)
    }
    fun inspect(authorized: Boolean, savedVersion: Long?) {
        val previous = state.value
        if (!authorized || savedVersion != previous.preferenceVersion) { cancel(); return }
        if (previous.phase != SharingReviewState.Phase.DISCLOSURE || !previous.acknowledged) return
        val permission = try { permissions.inspect(feature) } catch (_: Exception) { PermissionReadiness.UNKNOWN }
        mutableState.value = previous.copy(phase = SharingReviewState.Phase.RESULT, acknowledged = false, permission = permission)
    }
    fun cancel() { mutableState.value = SharingReviewState() }
}