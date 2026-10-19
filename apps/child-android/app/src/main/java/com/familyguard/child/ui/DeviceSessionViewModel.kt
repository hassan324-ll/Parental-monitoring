package com.familyguard.child.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.familyguard.child.data.CompanionSessionRepository
import com.familyguard.child.data.DeviceSession
import com.familyguard.child.data.KeystoreDeviceCredentialStore
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

class DeviceSessionViewModel(application: Application) : AndroidViewModel(application) {
    private val session = DeviceSession(
        KeystoreDeviceCredentialStore(application),
        CompanionSessionRepository()
    )
    private var operation: Job? = null
    val state = session.state

    init { refresh() }

    fun refresh() {
        if (operation?.isActive == true) return
        operation = viewModelScope.launch { session.restore() }
    }

    fun disconnect() {
        if (operation?.isActive == true) return
        operation = viewModelScope.launch { session.disconnect() }
    }
}