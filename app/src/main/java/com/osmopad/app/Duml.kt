package com.osmopad.app

/**
 * Kodek ramek DJI DUML, którymi gimbale Osmo Mobile rozmawiają przez BLE.
 *
 * Format ramki:
 *   [0]    0x55 (magic)
 *   [1..2] długość (10 bitów) | wersja (6 bitów) << 10, little-endian
 *   [3]    CRC-8 z bajtów [0..2]
 *   [4]    nadawca
 *   [5]    odbiorca
 *   [6..7] numer sekwencyjny, little-endian
 *   [8]    atrybuty (typ komendy)
 *   [9]    zestaw komend (cmd set)
 *   [10]   komenda
 *   [11..] payload
 *   [n-2..n-1] CRC-16 z całej ramki poza dwoma ostatnimi bajtami, little-endian
 *
 * Protokół jest nieoficjalny. Opisał go projekt alkersan/om-research (MIT),
 * przetestowany na OM2/OM3/OM4, a później na OM7P (shaun-xenon/osmo-remote).
 */
object Duml {
    const val MAGIC = 0x55
    private const val VERSION = 1

    const val SENDER_APP = 0x02
    const val RECEIVER_GIMBAL = 0x04

    const val CMDSET_GIMBAL = 0x04
    const val CMDSET_BATTERY = 0x05

    const val CMD_PWM = 0x01
    const val CMD_SPEED = 0x0C
    const val CMD_ABS_ANGLE = 0x14
    const val CMD_SET_MODE = 0x4C
    const val CMD_BATTERY = 0x06

    const val ROT_ABSOLUTE = 0x05
    const val ROT_SPEED = 0x80

    const val PWM_CENTER = 1024

    fun crc8(data: ByteArray, from: Int = 0, to: Int = data.size): Int {
        var crc = 0x77
        for (i in from until to) {
            crc = crc xor (data[i].toInt() and 0xFF)
            repeat(8) { crc = if (crc and 1 != 0) (crc ushr 1) xor 0x8C else crc ushr 1 }
        }
        return crc and 0xFF
    }

    fun crc16(data: ByteArray, from: Int = 0, to: Int = data.size): Int {
        var crc = 0x3692
        for (i in from until to) {
            crc = crc xor (data[i].toInt() and 0xFF)
            repeat(8) { crc = if (crc and 1 != 0) (crc ushr 1) xor 0x8408 else crc ushr 1 }
        }
        return crc and 0xFFFF
    }

    fun buildPacket(
        cmdSet: Int,
        cmd: Int,
        payload: ByteArray = ByteArray(0),
        seq: Int = 1,
        sender: Int = SENDER_APP,
        receiver: Int = RECEIVER_GIMBAL,
        attributes: Int = 0x00,
    ): ByteArray {
        val length = 13 + payload.size
        require(length <= 0x3FF) { "Payload too long" }
        val out = ByteArray(length)
        val lenRaw = length or (VERSION shl 10)
        out[0] = MAGIC.toByte()
        out[1] = (lenRaw and 0xFF).toByte()
        out[2] = (lenRaw ushr 8).toByte()
        out[3] = crc8(out, 0, 3).toByte()
        out[4] = sender.toByte()
        out[5] = receiver.toByte()
        out[6] = (seq and 0xFF).toByte()
        out[7] = ((seq ushr 8) and 0xFF).toByte()
        out[8] = attributes.toByte()
        out[9] = cmdSet.toByte()
        out[10] = cmd.toByte()
        payload.copyInto(out, 11)
        val crc = crc16(out, 0, length - 2)
        out[length - 2] = (crc and 0xFF).toByte()
        out[length - 1] = (crc ushr 8).toByte()
        return out
    }

    private fun le16(v: Int) = byteArrayOf((v and 0xFF).toByte(), ((v ushr 8) and 0xFF).toByte())

    /**
     * Obrót z zadaną prędkością. Wartości w dziesiątych częściach stopnia na sekundę.
     * [time] to czas trwania w dziesiątych sekundy: jeśli połączenie zerwie się,
     * gimbal sam się zatrzyma po tym czasie.
     */
    fun speedRotate(yaw: Int, pitch: Int, roll: Int = 0, time: Int = 10, seq: Int = 1): ByteArray =
        buildPacket(
            CMDSET_GIMBAL, CMD_SPEED,
            le16(yaw) + le16(roll) + le16(pitch) + byteArrayOf(ROT_SPEED.toByte(), time.toByte()),
            seq,
        )

    /** Obrót do kąta bezwzględnego (dziesiąte części stopnia). */
    fun absoluteRotate(yaw: Int, pitch: Int, roll: Int = 0, time: Int = 20, seq: Int = 1): ByteArray =
        buildPacket(
            CMDSET_GIMBAL, CMD_ABS_ANGLE,
            le16(yaw) + le16(roll) + le16(pitch) + byteArrayOf(ROT_ABSOLUTE.toByte(), time.toByte()),
            seq,
        )

    const val PWM_MIN = 363
    const val PWM_MAX = 1685

    /** Przelicza -1..1 na kanał PWM (środek 1024), jak fizyczny joystick gimbala. */
    fun axisToPwm(v: Float): Int {
        val c = v.coerceIn(-1f, 1f)
        val span = if (c >= 0) PWM_MAX - PWM_CENTER else PWM_CENTER - PWM_MIN
        return (PWM_CENTER + c * span).toInt().coerceIn(PWM_MIN, PWM_MAX)
    }

    /** Emulacja joysticka gimbala (alternatywa, gdy SPEED nie działa). */
    fun pwm(yaw: Int, pitch: Int, roll: Int = PWM_CENTER, seq: Int = 1): ByteArray =
        buildPacket(CMDSET_GIMBAL, CMD_PWM, le16(yaw) + le16(roll) + le16(pitch), seq)

    /** Tryb gimbala: 0 = lock, 1 = follow, 2 = FPV. Eksperymentalne. */
    fun setMode(mode: Int, seq: Int = 1): ByteArray =
        buildPacket(CMDSET_GIMBAL, CMD_SET_MODE, byteArrayOf(mode.toByte(), 0), seq)

    data class Packet(
        val sender: Int,
        val receiver: Int,
        val seq: Int,
        val attributes: Int,
        val cmdSet: Int,
        val cmd: Int,
        val payload: ByteArray,
        val crcOk: Boolean,
    ) {
        override fun toString(): String =
            "set=%02x cmd=%02x %02x->%02x seq=%d %s%s".format(
                cmdSet, cmd, sender, receiver, seq, payload.toHex(),
                if (crcOk) "" else " (zła CRC)",
            )
    }

    /** Składa ramki z fragmentów przychodzących w powiadomieniach BLE. */
    class Parser {
        private var buf = ByteArray(0)

        fun feed(data: ByteArray): List<Packet> {
            buf += data
            val result = mutableListOf<Packet>()
            while (true) {
                if (buf.isEmpty()) break
                if ((buf[0].toInt() and 0xFF) != MAGIC) {
                    val idx = buf.indexOfFirst { (it.toInt() and 0xFF) == MAGIC }
                    buf = if (idx < 0) ByteArray(0) else buf.copyOfRange(idx, buf.size)
                    continue
                }
                if (buf.size < 4) break
                val length = ((buf[1].toInt() and 0xFF) or ((buf[2].toInt() and 0xFF) shl 8)) and 0x3FF
                if (length < 13 || crc8(buf, 0, 3) != (buf[3].toInt() and 0xFF)) {
                    buf = buf.copyOfRange(1, buf.size)
                    continue
                }
                if (buf.size < length) break
                val raw = buf.copyOfRange(0, length)
                buf = buf.copyOfRange(length, buf.size)
                val crc = (raw[length - 2].toInt() and 0xFF) or ((raw[length - 1].toInt() and 0xFF) shl 8)
                result += Packet(
                    sender = raw[4].toInt() and 0xFF,
                    receiver = raw[5].toInt() and 0xFF,
                    seq = (raw[6].toInt() and 0xFF) or ((raw[7].toInt() and 0xFF) shl 8),
                    attributes = raw[8].toInt() and 0xFF,
                    cmdSet = raw[9].toInt() and 0xFF,
                    cmd = raw[10].toInt() and 0xFF,
                    payload = raw.copyOfRange(11, length - 2),
                    crcOk = crc16(raw, 0, length - 2) == crc,
                )
            }
            if (buf.size > 4096) buf = ByteArray(0)
            return result
        }
    }
}

fun ByteArray.toHex(): String = joinToString(" ") { "%02x".format(it.toInt() and 0xFF) }

fun String.hexToBytes(): ByteArray {
    val clean = replace(" ", "")
    return ByteArray(clean.length / 2) { clean.substring(it * 2, it * 2 + 2).toInt(16).toByte() }
}
