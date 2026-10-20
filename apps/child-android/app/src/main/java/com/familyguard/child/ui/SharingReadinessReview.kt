package com.familyguard.child.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.familyguard.child.data.*

@Composable
fun SharingReadinessReview(feature: SharingFeature, authorized: Boolean, preferenceVersion: Long?, busy: Boolean) {
    val context = LocalContext.current
    val review = remember(feature) { SharingPermissionReview(feature, AndroidSharingPermissionReader(context)) }
    val state by review.state.collectAsStateWithLifecycle()
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    LaunchedEffect(authorized, preferenceVersion, busy) { review.cancel() }
    DisposableEffect(review, lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_PAUSE || event == Lifecycle.Event.ON_STOP) review.cancel()
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer); review.cancel() }
    }
    OutlinedButton(onClick = { review.open(authorized, preferenceVersion) }, enabled = authorized && preferenceVersion != null && !busy) {
        Text("Review future permission requirements")
    }
    if (state.phase != SharingReviewState.Phase.CLOSED) {
        AlertDialog(onDismissRequest = review::cancel,
            title = { Text("Permission readiness — collection unavailable") },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text(if (feature == SharingFeature.USAGE)
                        "Future activity sharing would send app identifiers and daily foreground seconds, kept for seven UTC dates. Private messages and typed text are excluded. Android usage access would be required."
                        else "Future location sharing would send coarse coordinates, kept for 24 hours. Android location permission would be required.")
                    Text("This check only inspects this app's current Android access. It does not request permission, send consent, read private data or start sharing. A fresh choice and required Android permissions will still be needed before collection becomes available.")
                    if (state.phase == SharingReviewState.Phase.DISCLOSURE) {
                        Row {
                            Checkbox(checked = state.acknowledged, onCheckedChange = review::acknowledge)
                            Text("I understand this is a readiness check only.")
                        }
                    } else {
                        Text(when (state.permission) {
                            PermissionReadiness.GRANTED -> "Android access is currently allowed for this app. Sharing still cannot start in this version."
                            PermissionReadiness.NOT_GRANTED -> "Android access is not granted. Nothing was requested or collected."
                            else -> "Android access could not be determined. No permission or sharing is assumed."
                        })
                        Text("No consent was sent. Review again after any device, preference or permission change.")
                    }
                }
            },
            confirmButton = {
                if (state.phase == SharingReviewState.Phase.DISCLOSURE) TextButton(
                    enabled = state.acknowledged && authorized && !busy && preferenceVersion == state.preferenceVersion,
                    onClick = { review.inspect(authorized && !busy, preferenceVersion) }) { Text("Check current access") }
                else TextButton(onClick = review::cancel) { Text("Close") }
            },
            dismissButton = { TextButton(onClick = review::cancel) { Text("Cancel") } })
    }
}