package com.familyguard.child.data

import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.SocketPolicy
import okio.Buffer
import java.net.HttpURLConnection
import java.net.InetAddress
import java.net.URL
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test

/** Real loopback HTTP and JVM JSON; Android platform networking/JSON still need device acceptance. */
class ApiAdapterHttpTest {
    private val token = "d".repeat(64)
    private val hash = CredentialTokens.hash(token)
    private val code = "A".repeat(32)
    private class Recorded(val method: String, val path: String, val authorization: String?, val body: String) {
        override fun toString() = "Recorded(redacted)"
    }
    private lateinit var server: MockWebServer
    private val connections = mutableListOf<HttpURLConnection>()
    private lateinit var pairing: PairingRepository
    private lateinit var companion: CompanionSessionRepository

    @Before fun setUp() {
        server = MockWebServer()
        server.start(InetAddress.getByName("127.0.0.1"), 0)
        val factory: (URL) -> HttpURLConnection = { original ->
            // Keep production URL validation and paths; map only the physical connection to a synthetic local server.
            assertEquals("10.0.2.2", original.host)
            (URL("http://127.0.0.1:${server.port}${original.file}").openConnection() as HttpURLConnection)
                .also { connections.add(it) }
        }
        pairing = PairingRepository(factory)
        companion = CompanionSessionRepository(factory)
    }

    @After fun tearDown() {
        if (::server.isInitialized) server.shutdown()
    }

    private fun respond(status: Int = 200, body: String, location: String? = null) {
        val response = MockResponse().setResponseCode(status).setHeader("Content-Type", "application/json").setBody(body)
        if (location != null) response.setHeader("Location", location)
        server.enqueue(response)
    }
    private fun request(): Recorded {
        val request = requireNotNull(server.takeRequest(2, TimeUnit.SECONDS)) { "Expected synthetic HTTP request." }
        return Recorded(request.method.orEmpty(), request.path.orEmpty(), request.getHeader("Authorization"), request.body.readUtf8())
    }
    private suspend fun rejected(action: suspend () -> Unit): Exception {
        try { action() } catch (failure: Exception) { return failure }
        throw AssertionError("Expected the synthetic response to be rejected.")
    }

    @Test fun stagedApprovalPostsOnlyHashAndExplicitConsentWithNoBearer() = runBlocking {
        respond(201, """{"device":{"id":"synthetic-id","name":"Synthetic phone","paired":true}}""")
        pairing.approveStaged(code, "Synthetic phone", "26", hash)
        val request = request()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/device-pairings/approve", request.path)
        assertNull(request.authorization)
        val body = JSONObject(request.body)
        assertEquals(code, body.getString("code"))
        assertTrue(body.getBoolean("approved"))
        assertEquals(hash, body.getString("credential_hash"))
        assertFalse(request.body.contains(token))
        assertFalse(body.has("device_token"))
        assertFalse(body.has("family_id"))
        assertFalse(connections.single().useCaches)
        assertFalse(connections.single().instanceFollowRedirects)
        assertEquals(10000, connections.single().connectTimeout)
        assertEquals(10000, connections.single().readTimeout)
    }

    @Test fun previewUsesCorrectPathAndPreservesUnicodeNames() = runBlocking {
        respond(body = """{"family_name":"خاندان 🚗","child_name":"Synthetic child"}""")
        val preview = pairing.preview(code)
        assertEquals("خاندان 🚗", preview.familyName)
        val request = request()
        assertEquals("/api/v1/device-pairings/preview", request.path)
        assertNull(request.authorization)
        assertEquals(code, JSONObject(request.body).getString("code"))
    }

    @Test fun legacyApprovalStillReadsTokenAndRedactsItsDiagnosticOutput() = runBlocking {
        respond(201, """{"device_token":"$token","device":{"name":"Synthetic phone"}}""")
        val approval = pairing.approve(code, "Synthetic phone", "26")
        assertEquals(token, approval.deviceToken)
        assertFalse(approval.toString().contains(token))
        assertFalse(JSONObject(request().body).has("credential_hash"))
    }

    @Test fun companionStatusRequiresBearerAndVerifiedServerFlags() = runBlocking {
        respond(body = """{"paired":true,"device":{"id":"synthetic-id","name":"Synthetic phone","revoked":false}}""")
        assertEquals("Synthetic phone", companion.status(token).name)
        val request = request()
        assertEquals("GET", request.method)
        assertEquals("/api/v1/companion/me", request.path)
        assertEquals("Bearer $token", request.authorization)
        assertEquals("", request.body)
        respond(body = """{"paired":true,"device":{"id":"synthetic-id","name":"Synthetic phone","revoked":true}}""")
        assertTrue(rejected { companion.status(token) } is IllegalStateException)
    }

    @Test fun rotationPostsOnlyReplacementHashAndDisconnectPostsNoSecretBody() = runBlocking {
        respond(body = """{"rotated":true}""")
        companion.rotate(token, hash)
        val rotation = request()
        assertEquals("POST", rotation.method)
        assertEquals("/api/v1/companion/rotate", rotation.path)
        assertEquals("Bearer $token", rotation.authorization)
        assertEquals(hash, JSONObject(rotation.body).getString("next_credential_hash"))
        assertFalse(rotation.body.contains(token))
        respond(body = """{"message":"Device disconnected."}""")
        companion.disconnect(token)
        val disconnect = request()
        assertEquals("/api/v1/companion/disconnect", disconnect.path)
        assertEquals("POST", disconnect.method)
        assertEquals("Bearer $token", disconnect.authorization)
        assertEquals("", disconnect.body)
    }

    @Test fun unauthorizedIsTypedAndOtherHttpFailuresNeverEchoResponseBody() = runBlocking {
        respond(401, """{"message":"$token"}""")
        assertTrue(rejected { companion.status(token) } is DeviceUnauthorizedException)
        request()
        respond(503, """{"message":"$token"}""")
        val companionFailure = rejected { companion.status(token) }
        assertFalse(companionFailure is DeviceUnauthorizedException)
        assertFalse(companionFailure.toString().contains(token))
        request()
        respond(503, "{}")
        val pairingFailure = rejected { pairing.preview(code) }
        assertFalse(pairingFailure.toString().contains(token))
    }

    @Test fun redirectsAreRejectedWithoutFollowingOrForwardingBearer() = runBlocking {
        respond(302, "{}", "http://127.0.0.1:${server.port}/trap")
        rejected { companion.status(token) }
        assertEquals("/api/v1/companion/me", request().path)
        assertNull(server.takeRequest(200, TimeUnit.MILLISECONDS))
        respond(302, "{}", "http://127.0.0.1:${server.port}/trap")
        rejected { pairing.approveStaged(code, "Synthetic phone", "26", hash) }
        assertEquals("/api/v1/device-pairings/approve", request().path)
        assertNull(server.takeRequest(200, TimeUnit.MILLISECONDS))
        assertEquals(2, connections.size)
    }

    @Test fun oversizedAndInvalidUtf8AreRejectedThroughRealHttpResponses() = runBlocking {
        respond(body = "x".repeat(ApiResponseReader.MAX_BYTES + 1))
        assertEquals("API response too large.", rejected { pairing.preview(code) }.message)
        request()
        server.enqueue(MockResponse().setBody(Buffer().write(byteArrayOf(0xc3.toByte(), 0x28))))
        assertEquals("API response is not valid UTF-8.", rejected { companion.status(token) }.message)
    }

    @Test fun stagedModeRejectsLegacyTokenResponseAndUnconfirmedRotation() = runBlocking {
        respond(201, """{"device_token":"$token","device":{"paired":true}}""")
        val failure = rejected { pairing.approveStaged(code, "Synthetic phone", "26", hash) }
        assertEquals("Unexpected staged approval response.", failure.message)
        assertFalse(failure.toString().contains(token))
        request()
        respond(body = """{"rotated":false}""")
        assertEquals("Rotation is unconfirmed.", rejected { companion.rotate(token, hash) }.message)
    }

    @Test fun lostApprovalResponseDoesNotAutomaticallyReplayPost() = runBlocking {
        server.enqueue(MockResponse().setSocketPolicy(SocketPolicy.DISCONNECT_AFTER_REQUEST))
        respond(404, """{"message":"Consumed invitation"}""") // A replay would receive this response.
        rejected { pairing.approveStaged(code, "Synthetic phone", "26", hash) }
        assertEquals("/api/v1/device-pairings/approve", request().path)
        assertNull("Approval POST must not be retried by the transport.", server.takeRequest(200, TimeUnit.MILLISECONDS))
        assertEquals(1, server.requestCount)
    }

    @Test fun malformedJsonAndMissingAuthorizationFlagsAreRejected() = runBlocking {
        respond(body = "{")
        rejected { pairing.preview(code) }
        request()
        respond(body = """{"device":{"id":"synthetic-id","name":"Synthetic phone","revoked":false}}""")
        rejected { companion.status(token) }
        request()
        respond(body = """{"device":{"name":"Synthetic phone"}}""")
        rejected { pairing.approveStaged(code, "Synthetic phone", "26", hash) }
        Unit
    }

    @Test fun invalidCredentialsAndHashesAreRejectedBeforeOpeningConnection() = runBlocking {
        rejected { companion.status("invalid") }
        rejected { companion.rotate(token, "invalid") }
        rejected { pairing.approveStaged(code, "Synthetic phone", "26", "invalid") }
        assertEquals(0, connections.size)
        assertEquals(0, server.requestCount)
    }
}
