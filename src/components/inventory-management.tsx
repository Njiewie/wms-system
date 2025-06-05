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
  BarChart3,
  Barcode,
  Calculator,
  Package2,
  Shield,
  Save,
  X,
  User,
  Building,
  Ruler,
  Weight,
  XCircle
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger, DropdownMenuSeparator } from "@/components/ui/dropdown-menu"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import { Alert, AlertDescription } from "@/components/ui/alert"

// Enhanced SKU Master data structure
const skuMasterData = [
  {
    id: "SKU-001",
    sku: "PRD-ABC-123",
    description: "Premium Widget Assembly",
    category: "Electronics",
    dimensions: { length: 10, width: 8, height: 6, unit: "cm" },
    weight: { value: 0.5, unit: "kg" },
    unitCost: 45.99,
    reorderLevel: 100,
    maxStock: 5000,
    clients: ["TechCorp", "InnovativeTech"],
    barcode: "1234567890123",
    active: true,
    createdBy: "admin",
    createdAt: "2024-01-10T10:00:00",
    lastModified: "2024-01-15T14:30:00",
    modifiedBy: "warehouse_manager",
    hazardous: false,
    temperature: "ambient",
    shelfLife: null,
    notes: "High-demand item, check stock weekly"
  },
  {
    id: "SKU-002",
    sku: "PRD-DEF-456",
    description: "Standard Component Set",
    category: "Components",
    dimensions: { length: 15, width: 12, height: 8, unit: "cm" },
    weight: { value: 1.2, unit: "kg" },
    unitCost: 32.75,
    reorderLevel: 200,
    maxStock: 3000,
    clients: ["TechCorp", "ManufacturingCorp"],
    barcode: "2345678901234",
    active: true,
    createdBy: "sku_manager",
    createdAt: "2024-01-08T09:15:00",
    lastModified: "2024-01-14T11:20:00",
    modifiedBy: "admin",
    hazardous: false,
    temperature: "ambient",
    shelfLife: null,
    notes: "Standard inventory item"
  },
  {
    id: "SKU-003",
    sku: "PRD-GHI-789",
    description: "Specialized Tool Kit",
    category: "Tools",
    dimensions: { length: 25, width: 20, height: 10, unit: "cm" },
    weight: { value: 2.8, unit: "kg" },
    unitCost: 89.99,
    reorderLevel: 50,
    maxStock: 500,
    clients: ["QuickFix", "TechCorp"],
    barcode: "3456789012345",
    active: true,
    createdBy: "inventory_specialist",
    createdAt: "2024-01-05T13:45:00",
    lastModified: "2024-01-12T16:10:00",
    modifiedBy: "sku_manager",
    hazardous: true,
    temperature: "ambient",
    shelfLife: null,
    notes: "Contains sharp tools - handle with care"
  }
]

// Mock clients data
const clientsData = [
  { id: "CLI-001", name: "TechCorp", code: "TECH", active: true },
  { id: "CLI-002", name: "InnovativeTech", code: "INNO", active: true },
  { id: "CLI-003", name: "ManufacturingCorp", code: "MANU", active: true },
  { id: "CLI-004", name: "QuickFix", code: "QFIX", active: true }
]

// Categories data
const categoriesData = [
  "Electronics", "Components", "Tools", "Raw Materials", "Finished Goods", "Packaging"
]

// User permissions mock
const userPermissions = {
  canCreate: true,
  canEdit: true,
  canDelete: false, // Restricted for this user
  canViewAll: true,
  isAdmin: false
}

// Enhanced SKU Master Create/Edit Dialog with Security
function SKUMasterDialog({ sku, mode = "create" }: { sku?: typeof skuMasterData[0], mode?: "create" | "edit" }) {
  const [open, setOpen] = useState(false)
  const [formData, setFormData] = useState({
    sku: sku?.sku || '',
    description: sku?.description || '',
    category: sku?.category || '',
    unitCost: sku?.unitCost || 0,
    reorderLevel: sku?.reorderLevel || 0,
    maxStock: sku?.maxStock || 0,
    barcode: sku?.barcode || '',
    active: sku?.active ?? true,
    hazardous: sku?.hazardous || false,
    notes: sku?.notes || '',
    selectedClients: sku?.clients || []
  })

  const handleSave = () => {
    console.log('Saving SKU:', { mode, formData })
    setOpen(false)
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        {mode === "create" ? (
          <Button className="bg-blue-600 hover:bg-blue-700">
            <Plus className="mr-2 h-4 w-4" />
            Create SKU
          </Button>
        ) : (
          <Button variant="ghost" size="icon">
            <Edit className="h-4 w-4" />
          </Button>
        )}
      </DialogTrigger>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {mode === "create" ? "Create New SKU" : `Edit SKU - ${sku?.sku}`}
          </DialogTitle>
          <DialogDescription>
            {mode === "create"
              ? "Create a new SKU master record"
              : "Update SKU master information"
            }
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {!userPermissions.isAdmin && (
            <Alert>
              <Shield className="h-4 w-4" />
              <AlertDescription>
                You have limited permissions. Some fields may be restricted based on your role.
              </AlertDescription>
            </Alert>
          )}

          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="sku">SKU Code *</Label>
              <Input
                id="sku"
                value={formData.sku}
                onChange={(e) => setFormData({...formData, sku: e.target.value.toUpperCase()})}
                placeholder="PRD-ABC-123"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="category">Category *</Label>
              <Select value={formData.category} onValueChange={(value) => setFormData({...formData, category: value})}>
                <SelectTrigger>
                  <SelectValue placeholder="Select category" />
                </SelectTrigger>
                <SelectContent>
                  {categoriesData.map((category) => (
                    <SelectItem key={category} value={category}>
                      {category}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="description">Description *</Label>
            <Input
              id="description"
              value={formData.description}
              onChange={(e) => setFormData({...formData, description: e.target.value})}
              placeholder="Detailed product description"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="barcode">Barcode (EAN-13)</Label>
            <div className="relative">
              <Barcode className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
              <Input
                id="barcode"
                value={formData.barcode}
                onChange={(e) => setFormData({...formData, barcode: e.target.value})}
                placeholder="1234567890123"
                className="pl-10"
                maxLength={13}
              />
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="unitCost">Unit Cost *</Label>
              <div className="relative">
                <span className="absolute left-3 top-3 text-muted-foreground">$</span>
                <Input
                  id="unitCost"
                  type="number"
                  value={formData.unitCost}
                  onChange={(e) => setFormData({...formData, unitCost: Number(e.target.value)})}
                  className="pl-8"
                  min="0"
                  step="0.01"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="reorderLevel">Reorder Level *</Label>
              <Input
                id="reorderLevel"
                type="number"
                value={formData.reorderLevel}
                onChange={(e) => setFormData({...formData, reorderLevel: Number(e.target.value)})}
                min="0"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="maxStock">Maximum Stock *</Label>
              <Input
                id="maxStock"
                type="number"
                value={formData.maxStock}
                onChange={(e) => setFormData({...formData, maxStock: Number(e.target.value)})}
                min="0"
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label>Authorized Clients</Label>
            <div className="grid gap-2 md:grid-cols-2">
              {clientsData.filter(c => c.active).map((client) => (
                <div key={client.id} className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id={`client-${client.id}`}
                    checked={formData.selectedClients.includes(client.name)}
                    onChange={(e) => {
                      if (e.target.checked) {
                        setFormData({
                          ...formData,
                          selectedClients: [...formData.selectedClients, client.name]
                        })
                      } else {
                        setFormData({
                          ...formData,
                          selectedClients: formData.selectedClients.filter(c => c !== client.name)
                        })
                      }
                    }}
                    className="rounded"
                  />
                  <Label htmlFor={`client-${client.id}`} className="text-sm">
                    {client.name} ({client.code})
                  </Label>
                </div>
              ))}
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="active"
                checked={formData.active}
                onChange={(e) => setFormData({...formData, active: e.target.checked})}
                className="rounded"
              />
              <Label htmlFor="active">Active SKU</Label>
            </div>
            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="hazardous"
                checked={formData.hazardous}
                onChange={(e) => setFormData({...formData, hazardous: e.target.checked})}
                className="rounded"
              />
              <Label htmlFor="hazardous">Hazardous Material</Label>
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="notes">Notes & Instructions</Label>
            <textarea
              id="notes"
              value={formData.notes}
              onChange={(e) => setFormData({...formData, notes: e.target.value})}
              placeholder="Special handling instructions, notes, or comments..."
              className="w-full min-h-[80px] px-3 py-2 border border-input rounded-md bg-background"
            />
          </div>

          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleSave} className="bg-green-600 hover:bg-green-700">
              <Save className="mr-2 h-4 w-4" />
              {mode === "create" ? "Create SKU" : "Update SKU"}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function InventoryManagement() {
  const [searchTerm, setSearchTerm] = useState("")
  const [categoryFilter, setCategoryFilter] = useState("all")
  const [clientFilter, setClientFilter] = useState("all")
  const [activeFilter, setActiveFilter] = useState("all")
  const [selectedSKUs, setSelectedSKUs] = useState<string[]>([])

  const filteredSKUs = skuMasterData.filter(sku => {
    const matchesSearch =
      sku.sku.toLowerCase().includes(searchTerm.toLowerCase()) ||
      sku.description.toLowerCase().includes(searchTerm.toLowerCase()) ||
      sku.barcode.includes(searchTerm)

    const matchesCategory = categoryFilter === "all" || sku.category === categoryFilter
    const matchesClient = clientFilter === "all" || sku.clients.includes(clientsData.find(c => c.id === clientFilter)?.name || '')
    const matchesActive = activeFilter === "all" || (activeFilter === "active" ? sku.active : !sku.active)

    return matchesSearch && matchesCategory && matchesClient && matchesActive
  })

  const toggleSelection = (id: string) => {
    setSelectedSKUs(prev =>
      prev.includes(id)
        ? prev.filter(item => item !== id)
        : [...prev, id]
    )
  }

  const selectAll = () => {
    setSelectedSKUs(filteredSKUs.map(sku => sku.id))
  }

  const clearSelection = () => {
    setSelectedSKUs([])
  }

  const skuStats = {
    total: skuMasterData.length,
    active: skuMasterData.filter(s => s.active).length,
    categories: [...new Set(skuMasterData.map(s => s.category))].length,
    hazardous: skuMasterData.filter(s => s.hazardous).length,
    totalValue: skuMasterData.reduce((sum, sku) => sum + sku.unitCost, 0),
    avgCost: skuMasterData.reduce((sum, sku) => sum + sku.unitCost, 0) / skuMasterData.length
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="wms-page-header">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-foreground">SKU Master Management</h1>
            <p className="text-muted-foreground">Manage product master data with secure access controls</p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" className="gap-2">
              <Download className="h-4 w-4" />
              Export SKUs
            </Button>
            {userPermissions.canCreate && <SKUMasterDialog mode="create" />}
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
                <p className="text-sm text-muted-foreground">Total SKUs</p>
                <p className="text-lg font-bold">{skuStats.total}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.1s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <CheckCircle className="h-4 w-4 text-green-500" />
              <div>
                <p className="text-sm text-muted-foreground">Active SKUs</p>
                <p className="text-lg font-bold">{skuStats.active}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.2s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <BarChart3 className="h-4 w-4 text-purple-500" />
              <div>
                <p className="text-sm text-muted-foreground">Categories</p>
                <p className="text-lg font-bold">{skuStats.categories}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.3s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <AlertCircle className="h-4 w-4 text-orange-500" />
              <div>
                <p className="text-sm text-muted-foreground">Hazardous</p>
                <p className="text-lg font-bold text-orange-600">{skuStats.hazardous}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.4s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <TrendingUp className="h-4 w-4 text-green-500" />
              <div>
                <p className="text-sm text-muted-foreground">Total Value</p>
                <p className="text-lg font-bold">${skuStats.totalValue.toFixed(2)}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="animate-fade-in" style={{ animationDelay: '0.5s' }}>
          <CardContent className="p-4">
            <div className="flex items-center gap-2">
              <Calculator className="h-4 w-4 text-blue-500" />
              <div>
                <p className="text-sm text-muted-foreground">Avg Cost</p>
                <p className="text-lg font-bold">${skuStats.avgCost.toFixed(2)}</p>
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
                  placeholder="Search by SKU, description, or barcode..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>

            <div className="flex items-center gap-2">
              <Label htmlFor="category-filter" className="text-sm">Category:</Label>
              <Select value={categoryFilter} onValueChange={setCategoryFilter}>
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Categories</SelectItem>
                  {categoriesData.map(category => (
                    <SelectItem key={category} value={category}>{category}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center gap-2">
              <Label htmlFor="client-filter" className="text-sm">Client:</Label>
              <Select value={clientFilter} onValueChange={setClientFilter}>
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Clients</SelectItem>
                  {clientsData.map(client => (
                    <SelectItem key={client.id} value={client.id}>{client.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center gap-2">
              <Label htmlFor="status-filter" className="text-sm">Status:</Label>
              <Select value={activeFilter} onValueChange={setActiveFilter}>
                <SelectTrigger className="w-32">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <Button variant="outline" size="sm" className="gap-2">
              <RefreshCw className="h-4 w-4" />
              Refresh
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* SKU Master Table */}
      <Card className="animate-slide-up" style={{ animationDelay: '0.1s' }}>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>SKU Master Data</CardTitle>
              <CardDescription>
                Showing {filteredSKUs.length} of {skuMasterData.length} SKUs
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto wms-scrollbar">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Unit Cost</TableHead>
                  <TableHead>Reorder Level</TableHead>
                  <TableHead>Max Stock</TableHead>
                  <TableHead>Clients</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Modified</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredSKUs.map((sku) => (
                  <TableRow key={sku.id}>
                    <TableCell className="font-mono font-medium">
                      <div className="flex items-center gap-2">
                        {sku.sku}
                        {sku.hazardous && (
                          <AlertCircle className="h-4 w-4 text-orange-500" title="Hazardous Material" />
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="max-w-48">
                      <div className="truncate" title={sku.description}>
                        {sku.description}
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{sku.category}</Badge>
                    </TableCell>
                    <TableCell className="text-right font-medium">
                      ${sku.unitCost.toFixed(2)}
                    </TableCell>
                    <TableCell className="text-right">
                      {sku.reorderLevel.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-right">
                      {sku.maxStock.toLocaleString()}
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        {sku.clients.slice(0, 2).map(client => (
                          <Badge key={client} variant="secondary" className="text-xs">
                            {clientsData.find(c => c.name === client)?.code || client}
                          </Badge>
                        ))}
                        {sku.clients.length > 2 && (
                          <Badge variant="secondary" className="text-xs">
                            +{sku.clients.length - 2}
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge className={sku.active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}>
                        {sku.active ? 'Active' : 'Inactive'}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-sm">
                      <div>
                        <div>{new Date(sku.lastModified).toLocaleDateString()}</div>
                        <div className="text-xs text-muted-foreground">by {sku.modifiedBy}</div>
                      </div>
                    </TableCell>
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
                          {userPermissions.canEdit && (
                            <DropdownMenuItem asChild>
                              <SKUMasterDialog sku={sku} mode="edit" />
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuItem>
                            <Package className="mr-2 h-4 w-4" />
                            View Inventory
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          {userPermissions.canDelete && (
                            <DropdownMenuItem className="text-red-600">
                              <XCircle className="mr-2 h-4 w-4" />
                              Deactivate SKU
                            </DropdownMenuItem>
                          )}
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
    </div>
  )
}
