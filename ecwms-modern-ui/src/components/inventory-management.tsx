"use client"

import { useState } from "react"
import {
  Package,
  Search,
  Filter,
  Download,
  Plus,
  Edit,
  Trash2,
  Eye,
  MapPin,
  Calendar,
  TrendingUp,
  TrendingDown,
  AlertCircle,
  CheckCircle,
  Clock,
  MoreHorizontal,
  RefreshCw,
  BarChart3
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger, DropdownMenuSeparator } from "@/components/ui/dropdown-menu"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog"

// Mock inventory data
const inventoryData = [
  {
    id: "INV-001",
    sku: "PRD-ABC-123",
    description: "Premium Widget Assembly",
    location: "A1-B2-C3",
    onHand: 1250,
    allocated: 85,
    available: 1165,
    condition: "OK1",
    batch: "BATCH-2024-001",
    expiry: "2025-06-15",
    lastMovement: "2024-01-15",
    client: "TechCorp",
    cost: 45.99,
    category: "Electronics"
  },
  {
    id: "INV-002",
    sku: "PRD-XYZ-789",
    description: "Standard Component Module",
    location: "B2-C3-D4",
    onHand: 850,
    allocated: 120,
    available: 730,
    condition: "OK2",
    batch: "BATCH-2024-002",
    expiry: "2024-12-30",
    lastMovement: "2024-01-14",
    client: "RetailMax",
    cost: 29.50,
    category: "Components"
  },
  {
    id: "INV-003",
    sku: "PRD-DEF-456",
    description: "Critical Safety Device",
    location: "C3-D4-E5",
    onHand: 45,
    allocated: 12,
    available: 33,
    condition: "QC1",
    batch: "BATCH-2024-003",
    expiry: "2025-03-20",
    lastMovement: "2024-01-13",
    client: "SafetyFirst",
    cost: 125.00,
    category: "Safety"
  },
  {
    id: "INV-004",
    sku: "PRD-GHI-012",
    description: "Low Stock Alert Item",
    location: "D4-E5-F6",
    onHand: 8,
    allocated: 5,
    available: 3,
    condition: "OK1",
    batch: "BATCH-2024-004",
    expiry: "2025-01-10",
    lastMovement: "2024-01-12",
    client: "QuickShip",
    cost: 15.75,
    category: "Consumables"
  }
]

const inventoryStats = {
  totalValue: 2845670,
  totalItems: 15847,
  locations: 456,
  lowStock: 23,
  expiringSoon: 12,
  qualityHolds: 3
}

function ConditionBadge({ condition }: { condition: string }) {
  const configs = {
    OK1: { bg: "bg-green-100 dark:bg-green-900/20", text: "text-green-800 dark:text-green-400", label: "Good" },
    OK2: { bg: "bg-blue-100 dark:bg-blue-900/20", text: "text-blue-800 dark:text-blue-400", label: "Fair" },
    QC1: { bg: "bg-yellow-100 dark:bg-yellow-900/20", text: "text-yellow-800 dark:text-yellow-400", label: "QC Hold" },
    DM1: { bg: "bg-red-100 dark:bg-red-900/20", text: "text-red-800 dark:text-red-400", label: "Damaged" }
  }

  const config = configs[condition as keyof typeof configs] || configs.OK1

  return (
    <Badge className={`${config.bg} ${config.text}`}>
      {config.label}
    </Badge>
  )
}

function StockLevelIndicator({ onHand, allocated, minLevel = 50 }: { onHand: number; allocated: number; minLevel?: number }) {
  const available = onHand - allocated
  const percentage = (available / minLevel) * 100

  let color = "bg-green-500"
  let icon = <CheckCircle className="h-3 w-3 text-green-600" />

  if (percentage < 20) {
    color = "bg-red-500"
    icon = <AlertCircle className="h-3 w-3 text-red-600" />
  } else if (percentage < 50) {
    color = "bg-yellow-500"
    icon = <Clock className="h-3 w-3 text-yellow-600" />
  }

  return (
    <div className="flex items-center gap-2">
      {icon}
      <div className="w-16 bg-gray-200 rounded-full h-1.5">
        <div
          className={`h-1.5 rounded-full ${color}`}
          style={{ width: `${Math.min(percentage, 100)}%` }}
        />
      </div>
      <span className="text-xs text-muted-foreground">{available}</span>
    </div>
  )
}

export function InventoryManagement() {
  const [searchTerm, setSearchTerm] = useState("")
  const [selectedItems, setSelectedItems] = useState<string[]>([])
  const [filterCondition, setFilterCondition] = useState("all")
  const [sortBy, setSortBy] = useState("sku")

  const filteredData = inventoryData.filter(item => {
    const matchesSearch =
      item.sku.toLowerCase().includes(searchTerm.toLowerCase()) ||
      item.description.toLowerCase().includes(searchTerm.toLowerCase()) ||
      item.location.toLowerCase().includes(searchTerm.toLowerCase())

    const matchesFilter = filterCondition === "all" || item.condition === filterCondition

    return matchesSearch && matchesFilter
  })

  const toggleSelection = (id: string) => {
    setSelectedItems(prev =>
      prev.includes(id)
        ? prev.filter(item => item !== id)
        : [...prev, id]
    )
  }

  const selectAll = () => {
    setSelectedItems(filteredData.map(item => item.id))
  }

  const clearSelection = () => {
    setSelectedItems([])
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="wms-page-header">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-foreground">Inventory Management</h1>
            <p className="text-muted-foreground">Track and manage your warehouse inventory in real-time</p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" className="gap-2">
              <Download className="h-4 w-4" />
              Export
            </Button>
            <Button className="gap-2">
              <Plus className="h-4 w-4" />
              Add Inventory
            </Button>
          </div>
        </div>
      </div>

      {/* Stats Overview */}
      <div className="grid gap-4 md:grid-cols-6">
        <Card className="animate-fade-in">
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <Package className="h-4 w-4 text-blue-500" />
              <div>
                <p className="text-sm text-muted-foreground">Total Value</p>
                <p className="text-lg font-bold">${inventoryStats.totalValue.toLocaleString()}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.1s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <BarChart3 className="h-4 w-4 text-green-500" />
              <div>
                <p className="text-sm text-muted-foreground">Total Items</p>
                <p className="text-lg font-bold">{inventoryStats.totalItems.toLocaleString()}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.2s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <MapPin className="h-4 w-4 text-purple-500" />
              <div>
                <p className="text-sm text-muted-foreground">Locations</p>
                <p className="text-lg font-bold">{inventoryStats.locations}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.3s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <AlertCircle className="h-4 w-4 text-orange-500" />
              <div>
                <p className="text-sm text-muted-foreground">Low Stock</p>
                <p className="text-lg font-bold text-orange-600">{inventoryStats.lowStock}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.4s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <Clock className="h-4 w-4 text-red-500" />
              <div>
                <p className="text-sm text-muted-foreground">Expiring Soon</p>
                <p className="text-lg font-bold text-red-600">{inventoryStats.expiringSoon}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.5s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <Eye className="h-4 w-4 text-yellow-500" />
              <div>
                <p className="text-sm text-muted-foreground">QC Holds</p>
                <p className="text-lg font-bold text-yellow-600">{inventoryStats.qualityHolds}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Filters and Search */}
      <Card className="animate-slide-up">
        <CardContent className="p-4">
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex-1 min-w-64">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Search by SKU, description, or location..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>

            <div className="flex items-center gap-2">
              <Label htmlFor="condition-filter" className="text-sm">Condition:</Label>
              <select
                id="condition-filter"
                value={filterCondition}
                onChange={(e) => setFilterCondition(e.target.value)}
                className="rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="all">All Conditions</option>
                <option value="OK1">Good (OK1)</option>
                <option value="OK2">Fair (OK2)</option>
                <option value="QC1">QC Hold (QC1)</option>
                <option value="DM1">Damaged (DM1)</option>
              </select>
            </div>

            <Button variant="outline" size="sm" className="gap-2">
              <Filter className="h-4 w-4" />
              More Filters
            </Button>

            <Button variant="outline" size="sm" className="gap-2">
              <RefreshCw className="h-4 w-4" />
              Refresh
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Selection Controls */}
      {selectedItems.length > 0 && (
        <Card className="bg-blue-50 dark:bg-blue-950/20 border-blue-200 dark:border-blue-800">
          <CardContent className="p-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-4">
                <span className="text-sm font-medium">
                  {selectedItems.length} item(s) selected
                </span>
                <Button variant="outline" size="sm" onClick={clearSelection}>
                  Clear Selection
                </Button>
              </div>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" className="gap-2">
                  <Edit className="h-4 w-4" />
                  Bulk Edit
                </Button>
                <Button variant="outline" size="sm" className="gap-2">
                  <Download className="h-4 w-4" />
                  Export Selected
                </Button>
                <Button variant="destructive" size="sm" className="gap-2">
                  <Trash2 className="h-4 w-4" />
                  Delete Selected
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Inventory Table */}
      <Card className="animate-slide-up" style={{ animationDelay: '0.1s' }}>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Inventory Items</CardTitle>
              <CardDescription>
                Showing {filteredData.length} of {inventoryData.length} items
              </CardDescription>
            </div>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" onClick={selectAll}>
                Select All
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="sm">
                    Sort by: {sortBy} ▼
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => setSortBy("sku")}>SKU</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setSortBy("description")}>Description</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setSortBy("location")}>Location</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setSortBy("onHand")}>Quantity</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => setSortBy("lastMovement")}>Last Movement</DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto wms-scrollbar">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">
                    <input
                      type="checkbox"
                      checked={selectedItems.length === filteredData.length}
                      onChange={() => selectedItems.length === filteredData.length ? clearSelection() : selectAll()}
                    />
                  </TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Location</TableHead>
                  <TableHead>On Hand</TableHead>
                  <TableHead>Allocated</TableHead>
                  <TableHead>Available</TableHead>
                  <TableHead>Stock Level</TableHead>
                  <TableHead>Condition</TableHead>
                  <TableHead>Batch</TableHead>
                  <TableHead>Expiry</TableHead>
                  <TableHead>Client</TableHead>
                  <TableHead>Cost</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredData.map((item) => (
                  <TableRow
                    key={item.id}
                    className={`wms-table-row ${selectedItems.includes(item.id) ? 'selected' : ''}`}
                    onClick={() => toggleSelection(item.id)}
                  >
                    <TableCell>
                      <input
                        type="checkbox"
                        checked={selectedItems.includes(item.id)}
                        onChange={() => toggleSelection(item.id)}
                      />
                    </TableCell>
                    <TableCell className="font-mono font-medium">{item.sku}</TableCell>
                    <TableCell className="max-w-48">
                      <div className="truncate" title={item.description}>
                        {item.description}
                      </div>
                    </TableCell>
                    <TableCell className="font-mono text-sm">{item.location}</TableCell>
                    <TableCell className="text-right font-medium">{item.onHand.toLocaleString()}</TableCell>
                    <TableCell className="text-right text-orange-600">{item.allocated.toLocaleString()}</TableCell>
                    <TableCell className="text-right font-medium">{item.available.toLocaleString()}</TableCell>
                    <TableCell>
                      <StockLevelIndicator
                        onHand={item.onHand}
                        allocated={item.allocated}
                        minLevel={100}
                      />
                    </TableCell>
                    <TableCell>
                      <ConditionBadge condition={item.condition} />
                    </TableCell>
                    <TableCell className="font-mono text-sm">{item.batch}</TableCell>
                    <TableCell className="text-sm">
                      {new Date(item.expiry).toLocaleDateString()}
                    </TableCell>
                    <TableCell>{item.client}</TableCell>
                    <TableCell className="text-right">${item.cost.toFixed(2)}</TableCell>
                    <TableCell>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon">
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem>
                            <Eye className="mr-2 h-4 w-4" />
                            View Details
                          </DropdownMenuItem>
                          <DropdownMenuItem>
                            <Edit className="mr-2 h-4 w-4" />
                            Edit Item
                          </DropdownMenuItem>
                          <DropdownMenuItem>
                            <TrendingUp className="mr-2 h-4 w-4" />
                            Movement History
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem className="text-red-600">
                            <Trash2 className="mr-2 h-4 w-4" />
                            Delete Item
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      {/* Pagination */}
      <div className="flex items-center justify-between">
        <div className="text-sm text-muted-foreground">
          Showing 1-{filteredData.length} of {inventoryData.length} results
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" disabled>
            Previous
          </Button>
          <Button variant="outline" size="sm" className="bg-primary text-primary-foreground">
            1
          </Button>
          <Button variant="outline" size="sm">
            2
          </Button>
          <Button variant="outline" size="sm">
            3
          </Button>
          <Button variant="outline" size="sm">
            Next
          </Button>
        </div>
      </div>
    </div>
  )
}
