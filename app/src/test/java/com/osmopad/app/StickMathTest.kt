package com.osmopad.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class StickMathTest {
    @Test
    fun deadzoneGivesZero() {
        assertEquals(0 to 0, StickMath.toSpeeds(0.1f, -0.1f, 1000))
    }

    @Test
    fun fullDeflectionGivesMaxSpeed() {
        assertEquals(1000 to 0, StickMath.toSpeeds(1f, 0f, 1000))
        assertEquals(-1000 to 0, StickMath.toSpeeds(-1f, 0f, 1000))
    }

    @Test
    fun stickUpTiltsCameraUp() {
        val (_, pitch) = StickMath.toSpeeds(0f, -1f, 1000)
        assertEquals(1000, pitch)
        val (_, inverted) = StickMath.toSpeeds(0f, -1f, 1000, invertTilt = true)
        assertEquals(-1000, inverted)
    }

    @Test
    fun curveIsMonotonicAndGentleNearCenter() {
        var prev = 0f
        for (i in 0..100) {
            val v = StickMath.shape(i / 100f)
            assertTrue(v >= prev)
            prev = v
        }
        assertTrue(StickMath.shape(0.3f) < 0.3f)
    }

    @Test
    fun triggers() {
        assertEquals(1f, StickMath.triggerMultiplier(0f, 0f), 1e-6f)
        assertEquals(2f, StickMath.triggerMultiplier(0f, 1f), 1e-6f)
        assertEquals(0.25f, StickMath.triggerMultiplier(1f, 0f), 1e-6f)
    }
}
