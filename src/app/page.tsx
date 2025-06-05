"use client"

import { useState } from "react"
import { WMSDashboard } from "@/components/wms-dashboard"
import { InventoryManagement } from "@/components/inventory-management"
import { OrderManagement } from "@/components/order-management"
import { AnalyticsDashboard } from "@/components/analytics-dashboard"

export default function Page() {
  const [currentView, setCurrentView] = useState("dashboard")

  const renderView = () => {
    switch (currentView) {
      case "inventory":
        return <InventoryManagement />
      case "orders":
        return <OrderManagement />
      case "analytics":
        return <AnalyticsDashboard />
      default:
        return <WMSDashboard />
    }
  }

  const navItems = [
    { id: "dashboard", label: "Dashboard", icon: "📊", description: "Overview & key metrics" },
    { id: "inventory", label: "Inventory", icon: "📦", description: "Stock management & tracking" },
    { id: "orders", label: "Orders", icon: "🚚", description: "Order processing & fulfillment" },
    { id: "analytics", label: "Analytics", icon: "📈", description: "Performance insights & reports" },
  ]

  return (
    <div className="min-h-screen bg-background">
      {/* Demo Navigation Header */}
      <div className="sticky top-0 z-50 bg-background/80 backdrop-blur-lg border-b">
        <div className="max-w-7xl mx-auto px-6 py-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                  <span className="text-lg">📦</span>
                </div>
                <div>
                  <h1 className="font-bold text-lg">ECWMS</h1>
                  <p className="text-xs text-muted-foreground">Enterprise Warehouse Management</p>
                </div>
              </div>
            </div>

            <div className="flex items-center gap-1 p-1 bg-muted rounded-lg">
              {navItems.map((item) => (
                <button
                  key={item.id}
                  onClick={() => setCurrentView(item.id)}
                  className={`
                    group relative px-4 py-2 rounded-md text-sm font-medium transition-all duration-200
                    ${currentView === item.id
                      ? "bg-background text-foreground shadow-sm"
                      : "text-muted-foreground hover:text-foreground hover:bg-background/50"
                    }
                  `}
                  title={item.description}
                >
                  <div className="flex items-center gap-2">
                    <span className="text-base">{item.icon}</span>
                    <span className="hidden sm:inline">{item.label}</span>
                  </div>

                  {/* Tooltip for mobile */}
                  <div className="absolute -bottom-8 left-1/2 transform -translate-x-1/2 px-2 py-1 bg-black text-white text-xs rounded opacity-0 group-hover:opacity-100 sm:hidden transition-opacity duration-200 whitespace-nowrap z-10">
                    {item.label}
                  </div>
                </button>
              ))}
            </div>

            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <div className="hidden md:flex items-center gap-1">
                <div className="w-2 h-2 bg-green-500 rounded-full animate-pulse" />
                <span>Live Demo</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Feature Banner */}
      {currentView === "dashboard" && (
        <div className="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-950/20 dark:to-indigo-950/20 border-b">
          <div className="max-w-7xl mx-auto px-6 py-6">
            <div className="flex items-center justify-between">
              <div>
                <h2 className="text-xl font-semibold text-foreground mb-2">
                  🚀 Modern WMS Interface Demo
                </h2>
                <p className="text-muted-foreground max-w-2xl">
                  Experience enterprise-grade warehouse management with our professional UI components,
                  real-time analytics, and comprehensive inventory tracking system.
                </p>
              </div>
              <div className="hidden lg:flex items-center gap-4 text-sm">
                <div className="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                  <div className="w-2 h-2 bg-green-500 rounded-full" />
                  <span>15,847 Items</span>
                </div>
                <div className="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                  <div className="w-2 h-2 bg-blue-500 rounded-full" />
                  <span>156 Active Orders</span>
                </div>
                <div className="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-800 rounded-lg shadow-sm">
                  <div className="w-2 h-2 bg-purple-500 rounded-full" />
                  <span>99.2% Accuracy</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Main Content */}
      <div className="max-w-7xl mx-auto">
        {renderView()}
      </div>

      {/* Footer */}
      <footer className="border-t bg-muted/30 mt-12">
        <div className="max-w-7xl mx-auto px-6 py-8">
          <div className="grid gap-8 md:grid-cols-4">
            <div>
              <h3 className="font-semibold mb-3">Features Demonstrated</h3>
              <ul className="space-y-2 text-sm text-muted-foreground">
                <li>• Real-time dashboard analytics</li>
                <li>• Advanced inventory management</li>
                <li>• Order workflow tracking</li>
                <li>• Performance metrics</li>
              </ul>
            </div>
            <div>
              <h3 className="font-semibold mb-3">UI Components</h3>
              <ul className="space-y-2 text-sm text-muted-foreground">
                <li>• Professional card layouts</li>
                <li>• Interactive data tables</li>
                <li>• Status badges & indicators</li>
                <li>• Responsive design</li>
              </ul>
            </div>
            <div>
              <h3 className="font-semibold mb-3">Technology Stack</h3>
              <ul className="space-y-2 text-sm text-muted-foreground">
                <li>• Next.js 15 with TypeScript</li>
                <li>• Tailwind CSS & shadcn/ui</li>
                <li>• Responsive & accessible</li>
                <li>• Modern animations</li>
              </ul>
            </div>
            <div>
              <h3 className="font-semibold mb-3">Enterprise Ready</h3>
              <ul className="space-y-2 text-sm text-muted-foreground">
                <li>• Scalable architecture</li>
                <li>• Security best practices</li>
                <li>• Performance optimized</li>
                <li>• Professional design</li>
              </ul>
            </div>
          </div>
          <div className="border-t pt-6 mt-6 text-center text-sm text-muted-foreground">
            <p>ECWMS - Enterprise Warehouse Management System Demo</p>
          </div>
        </div>
      </footer>
    </div>
  )
}
