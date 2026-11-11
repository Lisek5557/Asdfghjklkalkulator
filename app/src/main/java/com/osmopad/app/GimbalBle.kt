package com.osmopad.app

import android.annotation.SuppressLint
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothGatt
import android.bluetooth.BluetoothGattCallback
import android.bluetooth.BluetoothGattCharacteristic
import android.bluetooth.BluetoothGattDescriptor
import android.bluetooth.BluetoothManager
import android.bluetooth.BluetoothProfile
import android.bluetooth.BluetoothStatusCodes
import android.bluetooth.le.ScanCallback
import android.bluetooth.le.ScanResult
import android.bluetooth.le.ScanSettings
import android.content.Context
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.Looper
import android.os.ParcelUuid
import android.os.SystemClock
import java.util.ArrayDeque
import java.util.UUID

/**
 * Połączenie BLE z gimbalem Osmo Mobile.
 * Serwis 0xFFF0, powiadomienia 0xFFF4, zapis 0xFFF5.
 *
 * Wszystkie operacje GATT wykonywane są na jednym wątku [ble], bo Android
 * pozwala tylko na jedną operację GATT naraz.
 */
@SuppressLint("MissingPermission") // Uprawnienia sprawdza MainActivity przed użyciem.
class GimbalBle(context: Context, private val listener: Listener) {

    interface Listener {
        fun onStatus(text: String)
        fun onScanResult(device: BluetoothDevice, name: String, rssi: Int, likely: Boolean)
        fun onScanFinished()
        fun onReady(name: String)
        fun onDisconnected()
        fun onBattery(percent: Int, charging: Boolean)
        fun onPacket(packet: Duml.Packet)
        fun onLog(text: String)
    }

    companion object {
        val SERVICE: UUID = UUID.fromString("0000fff0-0000-1000-8000-00805f9b34fb")
        val CHAR_NOTIFY: UUID = UUID.fromString("0000fff4-0000-1000-8000-00805f9b34fb")
        val CHAR_WRITE: UUID = UUID.fromString("0000fff5-0000-1000-8000-00805f9b34fb")
        val CCCD: UUID = UUID.fromString("00002902-0000-1000-8000-00805f9b34fb")
        private val NAME_PREFIXES = listOf("OM", "OSMO", "DJI")
        private const val WRITE_TIMEOUT_MS = 250L
        private const val SCAN_MS = 10_000L
    }

    private val appContext = context.applicationContext
    private val adapter: BluetoothAdapter? =
        (appContext.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager).adapter
    private val main = Handler(Looper.getMainLooper())
    private val thread = HandlerThread("ble").apply { start() }
    private val ble = Handler(thread.looper)

    private var gatt: BluetoothGatt? = null
    private var writeChar: BluetoothGattCharacteristic? = null
    private var mtu = 23
    private var parser = Duml.Parser()
    private var deviceName = ""
    @Volatile var isReady = false
        private set

    // Kolejka zapisu: komendy ważne (stop, wyśrodkuj, tryb) idą przed ruchem,
    // a z komend ruchu zostaje tylko najnowsza, żeby nie było opóźnień.
    private val priority = ArrayDeque<ByteArray>()
    private var pendingMotion: ByteArray? = null
    private val chunks = ArrayDeque<ByteArray>()
    private var busy = false
    private var busySince = 0L
    private var seq = 1

    val isBluetoothOn: Boolean get() = adapter?.isEnabled == true

    private fun ui(block: () -> Unit) = main.post(block)
    private fun log(text: String) = ui { listener.onLog(text) }
    private fun status(text: String) = ui { listener.onStatus(text) }

    // ---------------------------------------------------------------- skanowanie

    private var scanning = false
    private val scanCallback = object : ScanCallback() {
        override fun onScanResult(callbackType: Int, result: ScanResult) {
            val name = result.scanRecord?.deviceName ?: result.device.name ?: ""
            val uuids = result.scanRecord?.serviceUuids ?: emptyList<ParcelUuid>()
            val hasService = uuids.any { it.uuid == SERVICE }
            val likely = hasService || NAME_PREFIXES.any { name.uppercase().startsWith(it) }
            ui { listener.onScanResult(result.device, name, result.rssi, likely) }
        }

        override fun onScanFailed(errorCode: Int) {
            scanning = false
            status("Błąd skanowania ($errorCode). Wyłącz i włącz Bluetooth.")
            ui { listener.onScanFinished() }
        }
    }

    fun startScan() {
        val scanner = adapter?.bluetoothLeScanner
        if (scanner == null || !isBluetoothOn) {
            status("Włącz Bluetooth w telefonie.")
            return
        }
        if (scanning) return
        scanning = true
        val settings = ScanSettings.Builder().setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY).build()
        scanner.startScan(null, settings, scanCallback)
        status("Szukam gimbala…")
        main.postDelayed({ stopScan() }, SCAN_MS)
    }

    fun stopScan() {
        if (!scanning) return
        scanning = false
        try {
            adapter?.bluetoothLeScanner?.stopScan(scanCallback)
        } catch (_: Exception) {
        }
        ui { listener.onScanFinished() }
    }

    // ---------------------------------------------------------------- połączenie

    fun connect(device: BluetoothDevice, name: String) {
        stopScan()
        ble.post {
            closeGatt()
            deviceName = name.ifBlank { device.address }
            status("Łączę z $deviceName…")
            log("connect ${device.address}")
            gatt = device.connectGatt(appContext, false, gattCallback, BluetoothDevice.TRANSPORT_LE)
        }
    }

    fun connect(address: String, name: String) {
        val a = adapter ?: return
        if (!BluetoothAdapter.checkBluetoothAddress(address)) return
        connect(a.getRemoteDevice(address), name)
    }

    fun disconnect() {
        ble.post {
            closeGatt()
            ui { listener.onDisconnected() }
        }
    }

    fun shutdown() {
        stopScan()
        ble.post {
            closeGatt()
            thread.quitSafely()
        }
    }

    private fun closeGatt() {
        isReady = false
        writeChar = null
        priority.clear()
        chunks.clear()
        pendingMotion = null
        busy = false
        gatt?.let {
            try {
                it.disconnect()
                it.close()
            } catch (_: Exception) {
            }
        }
        gatt = null
    }

    private val gattCallback = object : BluetoothGattCallback() {
        override fun onConnectionStateChange(g: BluetoothGatt, statusCode: Int, newState: Int) {
            ble.post {
                if (g != gatt) return@post
                if (newState == BluetoothProfile.STATE_CONNECTED) {
                    log("połączono (status $statusCode), wykrywam usługi")
                    status("Połączono, konfiguruję…")
                    g.requestConnectionPriority(BluetoothGatt.CONNECTION_PRIORITY_HIGH)
                    g.discoverServices()
                } else if (newState == BluetoothProfile.STATE_DISCONNECTED) {
                    log("rozłączono (status $statusCode)")
                    val wasReady = isReady
                    closeGatt()
                    status(
                        if (wasReady) "Gimbal rozłączony."
                        else "Nie udało się połączyć (kod $statusCode). Upewnij się, że gimbal NIE jest połączony z DJI Mimo na innym telefonie."
                    )
                    ui { listener.onDisconnected() }
                }
            }
        }

        override fun onServicesDiscovered(g: BluetoothGatt, statusCode: Int) {
            ble.post {
                if (g != gatt) return@post
                val service = g.getService(SERVICE)
                val notify = service?.getCharacteristic(CHAR_NOTIFY)
                val write = service?.getCharacteristic(CHAR_WRITE)
                log("usługi: " + g.services.joinToString { it.uuid.toString().substring(4, 8) })
                if (service == null || write == null) {
                    status("To urządzenie nie ma usługi FFF0/FFF5 – to chyba nie gimbal Osmo Mobile.")
                    closeGatt()
                    ui { listener.onDisconnected() }
                    return@post
                }
                writeChar = write
                if (notify != null) {
                    g.setCharacteristicNotification(notify, true)
                    val cccd = notify.getDescriptor(CCCD)
                    if (cccd != null) {
                        writeDescriptor(g, cccd, BluetoothGattDescriptor.ENABLE_NOTIFICATION_VALUE)
                        return@post // dalej w onDescriptorWrite
                    }
                }
                g.requestMtu(185)
            }
        }

        override fun onDescriptorWrite(g: BluetoothGatt, descriptor: BluetoothGattDescriptor, statusCode: Int) {
            ble.post {
                if (g != gatt) return@post
                log("powiadomienia włączone (status $statusCode)")
                if (!g.requestMtu(185)) markReady()
            }
        }

        override fun onMtuChanged(g: BluetoothGatt, newMtu: Int, statusCode: Int) {
            ble.post {
                if (g != gatt) return@post
                if (statusCode == BluetoothGatt.GATT_SUCCESS) mtu = newMtu
                log("MTU $mtu")
                markReady()
            }
        }

        override fun onCharacteristicWrite(g: BluetoothGatt, c: BluetoothGattCharacteristic, statusCode: Int) {
            ble.post {
                if (g != gatt) return@post
                busy = false
                if (statusCode != BluetoothGatt.GATT_SUCCESS) log("zapis nieudany: $statusCode")
                pump()
            }
        }

        @Deprecated("API < 33")
        override fun onCharacteristicChanged(g: BluetoothGatt, c: BluetoothGattCharacteristic) {
            // Na Androidzie 13+ system woła obie wersje – obsługujemy tylko nową.
            if (Build.VERSION.SDK_INT >= 33) return
            @Suppress("DEPRECATION")
            val value = c.value ?: return
            onNotify(value.copyOf())
        }

        override fun onCharacteristicChanged(g: BluetoothGatt, c: BluetoothGattCharacteristic, value: ByteArray) {
            onNotify(value)
        }
    }

    private fun markReady() {
        if (isReady) return
        isReady = true
        parser = Duml.Parser()
        status("Połączono z $deviceName")
        val n = deviceName
        ui { listener.onReady(n) }
    }

    private fun onNotify(value: ByteArray) {
        ble.post {
            for (p in parser.feed(value)) {
                if (p.cmdSet == Duml.CMDSET_BATTERY && p.cmd == Duml.CMD_BATTERY && p.payload.isNotEmpty()) {
                    val pct = p.payload[0].toInt() and 0xFF
                    val charging = p.payload.size >= 2 && p.payload.last().toInt() == 1
                    ui { listener.onBattery(pct, charging) }
                }
                ui { listener.onPacket(p) }
            }
        }
    }

    @Suppress("DEPRECATION")
    private fun writeDescriptor(g: BluetoothGatt, d: BluetoothGattDescriptor, value: ByteArray) {
        if (Build.VERSION.SDK_INT >= 33) {
            g.writeDescriptor(d, value)
        } else {
            d.value = value
            g.writeDescriptor(d)
        }
    }

    // ---------------------------------------------------------------- wysyłanie

    private fun nextSeq(): Int {
        val s = seq
        seq = if (seq >= 0xFFFF) 1 else seq + 1
        return s
    }

    /** Ruch z zadaną prędkością (dziesiąte °/s). Zastępuje poprzednią niewysłaną komendę ruchu. */
    fun sendSpeed(yaw: Int, pitch: Int) = ble.post {
        if (!isReady) return@post
        pendingMotion = Duml.speedRotate(yaw, pitch, 0, time = 10, seq = nextSeq())
        pump()
    }

    /** Ruch w trybie PWM (emulacja fizycznego joysticka). */
    fun sendPwm(yawAxis: Float, pitchAxis: Float) = ble.post {
        if (!isReady) return@post
        pendingMotion = Duml.pwm(Duml.axisToPwm(yawAxis), Duml.axisToPwm(pitchAxis), seq = nextSeq())
        pump()
    }

    /** Natychmiastowe zatrzymanie (oba warianty komend). */
    fun stop() = ble.post {
        if (!isReady) return@post
        pendingMotion = null
        priority.add(Duml.speedRotate(0, 0, 0, time = 10, seq = nextSeq()))
        priority.add(Duml.pwm(Duml.PWM_CENTER, Duml.PWM_CENTER, seq = nextSeq()))
        pump()
    }

    fun recenter() = ble.post {
        if (!isReady) return@post
        pendingMotion = null
        priority.add(Duml.speedRotate(0, 0, 0, time = 10, seq = nextSeq()))
        priority.add(Duml.absoluteRotate(0, 0, 0, time = 15, seq = nextSeq()))
        log("→ wyśrodkuj")
        pump()
    }

    fun setMode(mode: Int) = ble.post {
        if (!isReady) return@post
        priority.add(Duml.setMode(mode, seq = nextSeq()))
        log("→ tryb $mode")
        pump()
    }

    fun sendRaw(packet: ByteArray) = ble.post {
        if (!isReady) return@post
        priority.add(packet)
        log("→ ${packet.toHex()}")
        pump()
    }

    private fun pump() {
        val g = gatt ?: return
        val c = writeChar ?: return
        if (busy) {
            // Zabezpieczenie: niektóre telefony gubią potwierdzenie zapisu.
            if (SystemClock.elapsedRealtime() - busySince < WRITE_TIMEOUT_MS) {
                scheduleRetry(WRITE_TIMEOUT_MS)
                return
            }
            busy = false
        }
        if (chunks.isEmpty()) {
            val packet = priority.poll() ?: pendingMotion?.also { pendingMotion = null } ?: return
            val size = (mtu - 3).coerceAtLeast(20)
            var i = 0
            while (i < packet.size) {
                chunks.add(packet.copyOfRange(i, minOf(packet.size, i + size)))
                i += size
            }
        }
        val chunk = chunks.poll() ?: return
        val type = if (c.properties and BluetoothGattCharacteristic.PROPERTY_WRITE_NO_RESPONSE != 0)
            BluetoothGattCharacteristic.WRITE_TYPE_NO_RESPONSE
        else BluetoothGattCharacteristic.WRITE_TYPE_DEFAULT
        val ok = if (Build.VERSION.SDK_INT >= 33) {
            g.writeCharacteristic(c, chunk, type) == BluetoothStatusCodes.SUCCESS
        } else {
            @Suppress("DEPRECATION")
            c.writeType = type
            @Suppress("DEPRECATION")
            c.value = chunk
            @Suppress("DEPRECATION")
            g.writeCharacteristic(c)
        }
        if (ok) {
            busy = true
            busySince = SystemClock.elapsedRealtime()
            scheduleRetry(WRITE_TIMEOUT_MS)
        } else {
            // Stos BLE zajęty – spróbuj ponownie za chwilę.
            chunks.addFirst(chunk)
            scheduleRetry(15)
        }
    }

    private val retry = Runnable { pump() }

    private fun scheduleRetry(ms: Long) {
        ble.removeCallbacks(retry)
        ble.postDelayed(retry, ms)
    }
}
