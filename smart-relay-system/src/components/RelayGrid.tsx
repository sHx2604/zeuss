'use client'

import RelayCard from './RelayCard'

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

interface RelayGridProps {
  devices: RelayDevice[]
  onToggle: (id: string) => void
  onReset: (id: string) => void
}

export default function RelayGrid({ devices, onToggle, onReset }: RelayGridProps) {
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold tracking-tight">Kontrol Perangkat</h2>
        <p className="text-sm text-muted-foreground">
          {devices.length} perangkat terdaftar
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {devices.map((device) => (
          <RelayCard
            key={device.id}
            device={device}
            onToggle={() => onToggle(device.id)}
            onReset={() => onReset(device.id)}
          />
        ))}
      </div>
    </div>
  )
}
