package com.osmopad.app

import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.sign

/** Przelicza położenie gałki pada na prędkość obrotu gimbala. */
object StickMath {
    /** Martwa strefa: gałki Xboxa często lekko "dryfują" w spoczynku. */
    const val DEADZONE = 0.12f

    /**
     * Martwa strefa + krzywa wykładnicza: małe wychylenia dają bardzo
     * powolny, płynny ruch, a pełne wychylenie daje pełną prędkość.
     * [expo] 0 = liniowo, 1 = sześciennie.
     */
    fun shape(raw: Float, expo: Float = 0.6f, deadzone: Float = DEADZONE): Float {
        val v = raw.coerceIn(-1f, 1f)
        val a = abs(v)
        if (a < deadzone) return 0f
        val n = (a - deadzone) / (1f - deadzone)
        val curved = (1f - expo) * n + expo * n * n * n
        return sign(v) * curved
    }

    /**
     * @param x oś pozioma (-1 lewo .. 1 prawo) -> obrót (yaw)
     * @param y oś pionowa w konwencji Androida (-1 góra .. 1 dół) -> pochylenie (pitch)
     * @param maxSpeed prędkość maksymalna w dziesiątych części °/s
     * @param multiplier mnożnik (spust = boost / precyzja)
     * @return para (yaw, pitch) gotowa do wysłania
     */
    fun toSpeeds(
        x: Float,
        y: Float,
        maxSpeed: Int,
        multiplier: Float = 1f,
        invertPan: Boolean = false,
        invertTilt: Boolean = false,
        expo: Float = 0.6f,
    ): Pair<Int, Int> {
        val limit = 3600
        fun scale(v: Float) = max(-limit, min(limit, (v * maxSpeed * multiplier).roundToInt()))
        var yaw = scale(shape(x, expo))
        // Gałka do góry = kamera w górę.
        var pitch = scale(shape(-y, expo))
        if (invertPan) yaw = -yaw
        if (invertTilt) pitch = -pitch
        return yaw to pitch
    }

    /** Spusty: RT przyspiesza (do 2x), LT zwalnia (do 0.25x). */
    fun triggerMultiplier(lt: Float, rt: Float): Float {
        val l = lt.coerceIn(0f, 1f)
        val r = rt.coerceIn(0f, 1f)
        return (1f + r) * (1f - 0.75f * l)
    }
}
