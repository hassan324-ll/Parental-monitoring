package com.familyguard.child.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.familyguard.child.data.SharingFeature
import com.familyguard.child.data.SharingState

@Composable
fun SharingSettings(feature: SharingFeature, state: SharingState, authorized: Boolean, sessionBusy: Boolean,
    onRefresh: () -> Unit, onDecision: (SharingFeature, Boolean, Boolean, Long) -> Unit) {
    var pending by remember(feature) { mutableStateOf<Boolean?>(null) }
    var acknowledged by remember(feature) { mutableStateOf(false) }
    var revision by remember(feature) { mutableStateOf(0L) }
    val title = if (feature == SharingFeature.USAGE) "Activity" else "Location"
    val consent = state.snapshot?.consent(feature)
    val ready = authorized && !sessionBusy && !state.busy
    LaunchedEffect(authorized, feature) { if (authorized) onRefresh() }
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(if (feature == SharingFeature.USAGE)
            "Planned activity sharing includes app names and daily time totals. Private messages and typed text are never included."
            else "Planned location sharing uses coarse location with a 24-hour retention limit.")
        Text("This version saves your preference only. Nothing is collected and no Android permissions are requested. You will be asked again before collection can start in a future version.")
        Text(state.message)
        if (state.phase == SharingState.Phase.READY && consent != null) {
            Text(if (consent.enabled) "Preference saved — collection unavailable." else "Preference off — no collection.")
            Text("Retention when available: ${consent.retention}")
        }
        Button(onClick = onRefresh, enabled = ready) { Text("Refresh saved preference") }
        Button(onClick = { revision = state.revision; acknowledged = false; pending = true },
            enabled = ready && state.phase == SharingState.Phase.READY && consent?.enabled == false) { Text("Review and save preference") }
        OutlinedButton(onClick = { revision = state.revision; acknowledged = false; pending = false }, enabled = ready) {
            Text("Stop and delete $title data")
        }
        SharingReadinessReview(feature, authorized, if (state.phase == SharingState.Phase.READY && consent?.enabled == true) consent.version else null, sessionBusy)
        if (!authorized) Text("Verify the device connection before changing preferences. If unavailable, ask the parent to revoke this device.")
    }
    if (pending != null) {
        val enabling = pending == true
        AlertDialog(onDismissRequest = { pending = null; acknowledged = false },
            title = { Text(if (enabling) "Save $title preference?" else "Stop and delete $title data?") },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text(if (enabling) "This records your choice for this device. Collection is unavailable and saving does not authorize automatic collection in a future version. A new explicit choice will be required before activation."
                        else "This asks the server to turn off this preference and permanently delete this device's stored $title data. Other devices are unaffected. If the connection fails, deletion remains unconfirmed; retry or ask the parent to revoke the device.")
                    if (enabling) Row {
                        Checkbox(checked = acknowledged, onCheckedChange = { acknowledged = it })
                        Text("I understand that this saves a preference only and does not start collection.")
                    }
                }
            },
            confirmButton = { TextButton(enabled = ready && revision == state.revision && (!enabling || acknowledged), onClick = {
                onDecision(feature, enabling, acknowledged, revision); pending = null; acknowledged = false
            }) { Text(if (enabling) "Save preference" else "Stop and delete") } },
            dismissButton = { TextButton(onClick = { pending = null; acknowledged = false }) { Text("Cancel") } })
    }
}
