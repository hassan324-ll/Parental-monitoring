package com.familyguard.child.data

import android.Manifest
import android.app.AppOpsManager
import android.content.Context
import android.content.pm.PackageManager
import android.os.Process

/** Inspect only this app's access; never read usage/location or launch permission UI. */
class AndroidSharingPermissionReader(context: Context) : SharingPermissionReader {
    private val application = context.applicationContext
    @Suppress("DEPRECATION")
    override fun inspect(feature: SharingFeature): PermissionReadiness {
        return try {
            val granted = when (feature) {
                SharingFeature.USAGE -> {
                    val operations = application.getSystemService(AppOpsManager::class.java) ?: return PermissionReadiness.UNKNOWN
                    operations.checkOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, Process.myUid(), application.packageName) == AppOpsManager.MODE_ALLOWED
                }
                SharingFeature.LOCATION -> application.checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
            }
            if (granted) PermissionReadiness.GRANTED else PermissionReadiness.NOT_GRANTED
        } catch (_: Exception) { PermissionReadiness.UNKNOWN }
    }
}