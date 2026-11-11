package com.osmopad.app

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class DumlTest {

    // Prawdziwe ramki z gimbala Osmo Mobile (próbki z om-research), z nagłówkiem 55 15 04 a9.
    private val captured = listOf(
        "55 15 04 a9 02 03 1c 00 40 03 f8 ef 97 90 92 ef 39 63 f5 5e aa",
        "55 15 04 a9 02 04 47 00 40 04 14 c2 01 00 00 20 00 05 0f eb 63",
        "55 15 04 a9 02 04 5e 00 40 04 14 3e fe 00 00 20 00 05 0f a2 e7",
        "55 15 04 a9 02 04 73 00 40 04 14 c2 01 00 00 20 00 05 0f 2f 9a",
    )

    @Test
    fun headerCrc8MatchesCapture() {
        assertEquals(0xa9, Duml.crc8("55 15 04".hexToBytes()))
    }

    @Test
    fun crc16MatchesCapturedPackets() {
        for (hex in captured) {
            val raw = hex.hexToBytes()
            val expected = (raw[raw.size - 2].toInt() and 0xFF) or ((raw[raw.size - 1].toInt() and 0xFF) shl 8)
            assertEquals(hex, expected, Duml.crc16(raw, 0, raw.size - 2))
        }
    }

    @Test
    fun rebuildsCapturedAbsoluteRotate() {
        // 02 04 47 00 40 04 14 | yaw=450 roll=0 pitch=32 mode=05 time=0f
        val built = Duml.buildPacket(
            Duml.CMDSET_GIMBAL, Duml.CMD_ABS_ANGLE,
            "c2 01 00 00 20 00 05 0f".hexToBytes(),
            seq = 0x47, attributes = 0x40,
        )
        assertArrayEquals(captured[1].hexToBytes(), built)
    }

    @Test
    fun speedRotateLayout() {
        val p = Duml.speedRotate(yaw = 1500, pitch = -300, time = 10, seq = 1)
        assertEquals(21, p.size)
        assertEquals("55 15 04 a9 02 04 01 00 00 04 0c dc 05 00 00 d4 fe 80 0a", p.copyOfRange(0, 19).toHex())
    }

    @Test
    fun parserRoundTripAcrossChunks() {
        val a = Duml.speedRotate(100, 200, seq = 7)
        val b = Duml.setMode(1, seq = 8)
        val stream = byteArrayOf(0x00, 0x13) + a + b
        val parser = Duml.Parser()
        val out = mutableListOf<Duml.Packet>()
        stream.toList().chunked(5).forEach { out += parser.feed(it.toByteArray()) }
        assertEquals(2, out.size)
        assertTrue(out.all { it.crcOk })
        assertEquals(Duml.CMD_SPEED, out[0].cmd)
        assertEquals(7, out[0].seq)
        assertEquals(Duml.CMD_SET_MODE, out[1].cmd)
    }
}
