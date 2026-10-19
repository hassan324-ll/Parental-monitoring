package com.familyguard.child.data

import com.familyguard.child.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

interface PairingPreviewApi {
    suspend fun preview(code: String): PairingRepository.Preview
}

interface PairingApprovalApi {
    suspend fun approve(code: String, deviceName: String, androidVersion: String): PairingRepository.Approval
}

/** Network-only pairing API. Never log invitation codes or device credentials. */
class PairingRepository : PairingPreviewApi, PairingApprovalApi {
    data class Preview(val familyName: String, val childName: String)
    class Approval(val deviceToken: String, val deviceName: String) { override fun toString() = "Approval(credential=redacted)" }

    override suspend fun preview(code: String): Preview = withContext(Dispatchers.IO) {
        val result = request("device-pairings/preview", JSONObject().put("code", code))
        Preview(result.getString("family_name"), result.getString("child_name"))
    }

    override suspend fun approve(code: String, deviceName: String, androidVersion: String): Approval =
        withContext(Dispatchers.IO) {
            val body = JSONObject().put("code", code).put("approved", true)
                .put("device_name", deviceName).put("android_version", androidVersion)
            val result = request("device-pairings/approve", body)
            Approval(result.getString("device_token"), result.getJSONObject("device").getString("name"))
        }

    private fun request(path: String, body: JSONObject): JSONObject {
        require(BuildConfig.API_BASE_URL.startsWith("https://") ||
            (BuildConfig.DEBUG && BuildConfig.API_BASE_URL.startsWith("http://10.0.2.2:"))) {
            "A secure API endpoint is required."
        }
        val connection = (URL(BuildConfig.API_BASE_URL + path).openConnection() as HttpURLConnection)
        try {
            connection.instanceFollowRedirects = false
            connection.useCaches = false
            connection.requestMethod = "POST"
            connection.connectTimeout = 10000
            connection.readTimeout = 10000
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            if (connection.responseCode !in 200..299) {
                throw IllegalStateException("Pairing request rejected (HTTP ${connection.responseCode}).")
            }
            return JSONObject(ApiResponseReader.readUtf8(connection.inputStream))
        } finally {
            connection.disconnect()
        }
    }
}
