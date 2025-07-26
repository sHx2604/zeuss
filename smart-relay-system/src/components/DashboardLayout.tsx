'use client'

import { useTheme } from 'next-themes'
import { Moon, Sun, Zap, Settings, Activity } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

interface DashboardLayoutProps {
  children: React.ReactNode
}

export default function DashboardLayout({ children }: DashboardLayoutProps) {
  const { theme, setTheme } = useTheme()

  return (
    <div className="min-h-screen bg-gradient-to-br from-background via-background to-muted/20">
      {/* Header */}
      <header className="border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
        <div className="container mx-auto px-4 py-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-4">
              <div className="flex items-center space-x-2">
                <Zap className="h-8 w-8 text-primary" />
                <div>
                  <h1 className="text-2xl font-bold tracking-tight">Smart Relay System</h1>
                  <p className="text-sm text-muted-foreground">Kontrol dan Monitor Real-time</p>
                </div>
              </div>
            </div>

            <div className="flex items-center space-x-4">
              {/* Connection Status */}
              <div className="flex items-center space-x-2">
                <Activity className="h-4 w-4 text-green-500 animate-pulse" />
                <Badge variant="outline" className="text-green-600 border-green-200">
                  MQTT Connected
                </Badge>
              </div>

              {/* Theme Toggle */}
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="icon">
                    <Sun className="h-[1.2rem] w-[1.2rem] rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0" />
                    <Moon className="absolute h-[1.2rem] w-[1.2rem] rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100" />
                    <span className="sr-only">Toggle theme</span>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => setTheme("light")}>
                    Light
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setTheme("dark")}>
                    Dark
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setTheme("system")}>
                    System
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>

              {/* Settings */}
              <Button variant="outline" size="icon">
                <Settings className="h-4 w-4" />
              </Button>
            </div>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="container mx-auto px-4 py-8">
        {children}
      </main>

      {/* Footer */}
      <footer className="border-t bg-muted/50 py-6 mt-12">
        <div className="container mx-auto px-4">
          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <p>© 2025 Smart Relay System. Siap untuk integrasi PHP.</p>
            <p>v1.0.0 | Real-time IoT Control</p>
          </div>
        </div>
      </footer>
    </div>
  )
}
