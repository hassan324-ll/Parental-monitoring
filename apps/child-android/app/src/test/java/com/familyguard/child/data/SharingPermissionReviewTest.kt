package com.familyguard.child.data

import org.junit.Assert.*
import org.junit.Test

class SharingPermissionReviewTest {
    @Test fun missingAuthorizationOrPreferenceNeverOpensOrInspects() {
        var calls = 0
        val review = SharingPermissionReview(SharingFeature.USAGE) { calls++; PermissionReadiness.GRANTED }
        for ((authorized, version) in listOf(false to 1L, true to null, true to 0L, true to -1L)) {
            review.open(authorized, version); review.acknowledge(true); review.inspect(authorized, version)
            assertEquals(SharingReviewState.Phase.CLOSED, review.state.value.phase)
        }
        assertEquals(0, calls)
    }
    @Test fun acknowledgmentIsFreshAndRequiredBeforeInspection() {
        var calls = 0
        val review = SharingPermissionReview(SharingFeature.USAGE) { calls++; PermissionReadiness.GRANTED }
        review.open(true, 1); review.inspect(true, 1); assertEquals(0, calls)
        review.acknowledge(true); review.inspect(true, 1)
        assertEquals(1, calls); assertEquals(PermissionReadiness.GRANTED, review.state.value.permission)
        assertFalse(review.state.value.acknowledged)
        review.open(true, 1); assertFalse(review.state.value.acknowledged); assertNull(review.state.value.permission)
    }
    @Test fun deniedAndUnknownAccessNeverBecomeGranted() {
        for (permission in listOf(PermissionReadiness.NOT_GRANTED, PermissionReadiness.UNKNOWN)) {
            val review = SharingPermissionReview(SharingFeature.LOCATION) { permission }
            review.open(true, 1); review.acknowledge(true); review.inspect(true, 1)
            assertEquals(permission, review.state.value.permission)
            assertEquals(SharingReviewState.Phase.RESULT, review.state.value.phase)
        }
    }
    @Test fun permissionReaderFailureIsUnknownWithoutExposingExceptionDetails() {
        val review = SharingPermissionReview(SharingFeature.USAGE) { throw SecurityException("synthetic-sensitive-detail") }
        review.open(true, 1); review.acknowledge(true); review.inspect(true, 1)
        assertEquals(PermissionReadiness.UNKNOWN, review.state.value.permission)
        assertFalse(review.state.value.toString().contains("synthetic-sensitive-detail"))
    }
    @Test fun cancelledOrPausedReviewLosesAcknowledgmentAndPermissionResult() {
        var calls = 0
        val review = SharingPermissionReview(SharingFeature.USAGE) { calls++; PermissionReadiness.GRANTED }
        review.open(true, 1); review.acknowledge(true); review.cancel(); review.inspect(true, 1)
        assertEquals(0, calls); assertEquals(SharingReviewState(), review.state.value)
        review.open(true, 1); review.acknowledge(true); review.inspect(true, 1); review.cancel()
        assertEquals(SharingReviewState(), review.state.value)
    }
    @Test fun stalePreferenceOrLostAuthorizationClearsReviewBeforeInspection() {
        var calls = 0
        val review = SharingPermissionReview(SharingFeature.LOCATION) { calls++; PermissionReadiness.GRANTED }
        review.open(true, 1); review.acknowledge(true); review.inspect(true, 2)
        assertEquals(SharingReviewState(), review.state.value)
        review.open(true, 1); review.acknowledge(true); review.inspect(false, 1)
        assertEquals(SharingReviewState(), review.state.value); assertEquals(0, calls)
    }
    @Test fun repeatedInspectionCannotReuseAnAcknowledgment() {
        var calls = 0
        val review = SharingPermissionReview(SharingFeature.USAGE) { calls++; PermissionReadiness.GRANTED }
        review.open(true, 1); review.acknowledge(true); review.inspect(true, 1)
        review.acknowledge(true); review.inspect(true, 1)
        assertEquals(1, calls)
    }
    @Test fun featureSpecificReaderAndReconstructedReviewNeverRestoreConsent() {
        val features = mutableListOf<SharingFeature>()
        val reader = SharingPermissionReader { features.add(it); PermissionReadiness.NOT_GRANTED }
        for (feature in SharingFeature.entries) {
            val review = SharingPermissionReview(feature, reader)
            review.open(true, 1); review.acknowledge(true); review.inspect(true, 1)
            assertEquals(SharingReviewState(), SharingPermissionReview(feature, reader).state.value)
        }
        assertEquals(SharingFeature.entries.toList(), features)
    }
}