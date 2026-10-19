package com.familyguard.child.data

import com.familyguard.child.BuildConfig
import java.net.HttpURLConnection
import java.net.URL
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject

data class CompanionDevice(val id: String, val name: String)

/** A rejected device credential must never be confused with a transient network failure. */
class DeviceUnauthorizedException : Exception("Device authorization rejected.")

interface CompanionSessionApi {
    suspend fun status(credential: String): CompanionDevice
    suspend fun disconnect(credential: String)
}

/** Uses device credentials only. Never log bearer headers or forward them through redirects. */
class CompanionSessionRepository : CompanionSessionApi, CompanionRotationApi {
    override suspend fun status(credential: String): CompanionDevice = withContext(Dispatchers.IO) {
        val result = request("GET", "companion/me", credential)
        val device = result.getJSONObject("device")
        check(result.getBoolean("paired") && !device.getBoolean("revoked")) {
            "Unexpected companion status."
        }
        CompanionDevice(device.getString("id"), device.getString("name"))
    }

    override suspend fun disconnect(credential: String): Unit = withContext(Dispatchers.IO) {
        request("POST", "companion/disconnect", credential)
        Unit
    }

    override suspend fun rotate(currentCredential: String, nextCredentialHash: String): Unit = withContext(Dispatchers.IO) {
        require(nextCredentialHash.matches(Regex("[a-f0-9]{64}"))) { "Invalid replacement hash." }
        val response = request("POST", "companion/rotate", currentCredential,
            JSONObject().put("next_credential_hash", nextCredentialHash))
        check(response.getBoolean("rotated")) { "Rotation is unconfirmed." }
    }
    private fun request(method: String, path: String, credential: String, body: JSONObject? = null): JSONObject {
        require(credential.matches(Regex("[a-f0-9]{64}"))) { "Invalid device credential." }
        require(BuildConfig.API_BASE_URL.startsWith("https://") ||
            (BuildConfig.DEBUG && BuildConfig.API_BASE_URL.startsWith("http://10.0.2.2:"))) {
            "A secure API endpoint is required."
        }
        val connection = URL(BuildConfig.API_BASE_URL + path).openConnection() as HttpURLConnection
        try {
            connection.instanceFollowRedirects = false
            connection.useCaches = false
            connection.requestMethod = method
            connection.connectTimeout = 10000
            connection.readTimeout = 10000
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Authorization", "Bearer $credential")
            if (method == "POST") {
                connection.doOutput = true
                val payload = body?.toString()?.toByteArray(Charsets.UTF_8) ?: ByteArray(0)
                if (body != null) connection.setRequestProperty("Content-Type", "application/json")
                connection.setFixedLengthStreamingMode(payload.size)
                connection.outputStream.use { it.write(payload) }
            }
            val status = connection.responseCode
            if (status == 401) throw DeviceUnauthorizedException()
            check(status in 200..299) { "Companion request failed (HTTP $status)." }
            val buffer = ByteArray(65537)
            var size = 0
            connection.inputStream.use { input ->
                while (size < buffer.size) {
                    val count = input.read(buffer, size, buffer.size - size)
                    if (count < 0) break
                    size += count
                }
            }
            check(size <= 65536) { "Companion response too large." }
            return JSONObject(String(buffer, 0, size, Charsets.UTF_8))
        } finally {
            connection.disconnect()
        }
    }
}