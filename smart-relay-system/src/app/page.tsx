'use client'

import { useState, useEffect } from 'react'
import { ThemeProvider } from 'next-themes'
import DashboardLayout from '@/components/DashboardLayout'
import RelayGrid from '@/components/RelayGrid'
import StatusBar from '@/components/StatusBar'
import ControlPanel from '@/components/ControlPanel'

// Simulasi data relay
interface RelayDevice {
  id: string
  name: string
  location: string
  status: 'on' | 'off' | 'error'
  lastUpdate: Date
  voltage: number
  current: number
  power: number
  temperature: number
}

export default function SmartRelayDashboard() {
  // Use a fixed timestamp to avoid hydration mismatch
  const baseTime = new Date('2025-01-26T10:00:00Z')

  const [relayDevices, setRelayDevices] = useState<RelayDevice[]>([
    {
      id: 'relay_001',
      name: 'Lampu Ruang Tamu',
      location: 'Lantai 1 - Ruang Tamu',
      status: 'on',
      lastUpdate: baseTime,
      voltage: 220.5,
      current: 2.3,
      power: 506.15,
      temperature: 32.5
    },
    {
      id: 'relay_002',
      name: 'AC Kamar Tidur',
      location: 'Lantai 2 - Kamar Utama',
      status: 'off',
      lastUpdate: baseTime,
      voltage: 220.1,
      current: 0,
      power: 0,
      temperature: 28.2
    },
    {
      id: 'relay_003',
      name: 'Pompa Air',
      location: 'Belakang Rumah',
      status: 'on',
      lastUpdate: baseTime,
      voltage: 218.7,
      current: 4.5,
      power: 984.15,
      temperature: 45.8
    },
    {
      id: 'relay_004',
      name: 'Lampu Taman',
      location: 'Taman Depan',
      status: 'error',
      lastUpdate: baseTime,
      voltage: 0,
      current: 0,
      power: 0,
      temperature: 25.0
    }
  ])

  const [mqttStatus, setMqttStatus] = useState<'connected' | 'disconnected' | 'error'>('connected')

  // Simulasi update real-time data
  useEffect(() => {
    const interval = setInterval(() => {
      setRelayDevices(prev => prev.map(device => ({
        ...device,
        lastUpdate: new Date(),
        voltage: device.status === 'on' ? 220 + (Math.random() - 0.5) * 5 : 0,
        current: device.status === 'on' ? device.current + (Math.random() - 0.5) * 0.5 : 0,
        power: device.status === 'on' ? device.voltage * device.current : 0,
        temperature: device.temperature + (Math.random() - 0.5) * 2
      })))
    }, 3000)

    return () => clearInterval(interval)
  }, [])

  const toggleRelay = (id: string) => {
    setRelayDevices(prev => prev.map(device =>
      device.id === id
        ? {
            ...device,
            status: device.status === 'on' ? 'off' : device.status === 'off' ? 'on' : 'error',
            lastUpdate: new Date()
          }
        : device
    ))
  }

  const resetDevice = (id: string) => {
    setRelayDevices(prev => prev.map(device =>
      device.id === id
        ? {
            ...device,
            status: 'off',
            lastUpdate: new Date(),
            voltage: 0,
            current: 0,
            power: 0
          }
        : device
    ))
  }

  return (
    <ThemeProvider attribute="class" defaultTheme="system" enableSystem>
      <DashboardLayout>
        <div className="space-y-6">
          <StatusBar
            totalDevices={relayDevices.length}
            activeDevices={relayDevices.filter(d => d.status === 'on').length}
            errorDevices={relayDevices.filter(d => d.status === 'error').length}
            mqttStatus={mqttStatus}
          />

          <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <div className="lg:col-span-3">
              <RelayGrid
                devices={relayDevices}
                onToggle={toggleRelay}
                onReset={resetDevice}
              />
            </div>

            <div className="lg:col-span-1">
              <ControlPanel
                devices={relayDevices}
                mqttStatus={mqttStatus}
                onMqttReconnect={() => setMqttStatus('connected')}
              />
            </div>
          </div>
        </div>
      </DashboardLayout>
    </ThemeProvider>
  )
}
