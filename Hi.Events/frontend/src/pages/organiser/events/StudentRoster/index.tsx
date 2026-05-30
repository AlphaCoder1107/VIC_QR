import React, { useState, useEffect, useRef } from 'react';
import { useParams } from 'react-router';
import {
    Container,
    Paper,
    Tabs,
    Table,
    TextInput,
    Select,
    Button,
    Title,
    Text,
    Stack,
    Group,
    Badge,
    Pagination,
    Loader,
    Alert,
    Card,
    ThemeIcon,
    Code,
    Tooltip,
    Switch,
    SegmentedControl,
} from '@mantine/core';
import {
    IconUpload,
    IconDownload,
    IconSearch,
    IconCheck,
    IconX,
    IconAlertCircle,
    IconFileSpreadsheet,
    IconTable,
    IconRefresh,
    IconInfoCircle,
    IconSettings,
    IconTrash,
    IconCoin,
    IconPlus,
} from '@tabler/icons-react';
import { PageTitle } from '../../../../components/common/PageTitle';
import { PageBody } from '../../../../components/common/PageBody';
import { rosterClient, StudentRosterRecord, RosterImportResponse, FreePrefixRecord, PaymentVerificationRecord } from '../../../../api/roster.client';
import { useGetEvent } from '../../../../queries/useGetEvent';
import { showSuccess, showError } from '../../../../utilites/notifications';
import { prettyDate } from '../../../../utilites/dates.ts';

export const StudentRoster: React.FC = () => {
    const { eventId } = useParams<{ eventId: string }>();
    const { data: event, isLoading: isEventLoading } = useGetEvent(eventId);
    const organizerId = event?.organizer?.id;

    // Tabs state
    const [activeTab, setActiveTab] = useState<string | null>('import');

    // Tab 1: Upload state
    const [file, setFile] = useState<File | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [uploadResult, setUploadResult] = useState<RosterImportResponse | null>(null);
    const [isDragActive, setIsDragActive] = useState(false);

    // Tab 2: Roster List state
    const [rosterData, setRosterData] = useState<StudentRosterRecord[]>([]);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [totalRecords, setTotalRecords] = useState(0);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<string>('all');
    const [isLoadingList, setIsLoadingList] = useState(false);
    const [hideClones, setHideClones] = useState(true);

    // Tab 3: Pricing & Prefixes state
    const [prefixes, setPrefixes] = useState<FreePrefixRecord[]>([]);
    const [priceInput, setPriceInput] = useState<string>('200');
    const [isLoadingSettings, setIsLoadingSettings] = useState(false);
    const [newPrefix, setNewPrefix] = useState('');
    const [newPrefixLabel, setNewPrefixLabel] = useState('');
    const [isSavingPrefix, setIsSavingPrefix] = useState(false);
    const [isSavingPrice, setIsSavingPrice] = useState(false);

    // Tab 4: Verification Dashboard state
    const [verifications, setVerifications] = useState<PaymentVerificationRecord[]>([]);
    const [vPage, setVPage] = useState(1);
    const [vTotalPages, setVTotalPages] = useState(1);
    const [vTotalRecords, setVTotalRecords] = useState(0);
    const [vSearch, setVSearch] = useState('');
    const [vStatus, setVStatus] = useState<string>('PENDING');
    const [vSource, setVSource] = useState<string>('btech');
    const [isUploadingVerification, setIsUploadingVerification] = useState(false);
    const [isLoadingVerifications, setIsLoadingVerifications] = useState(false);

    const fileInputRef = useRef<HTMLInputElement>(null);
    const btechFileInputRef = useRef<HTMLInputElement>(null);
    const bcaFileInputRef = useRef<HTMLInputElement>(null);

    // Trigger roster fetch when active tab changes, or page/search/filter changes
    const fetchRoster = async () => {
        if (!eventId || !organizerId) return;
        setIsLoadingList(true);
        try {
            const res = await rosterClient.all(organizerId, eventId, {
                page,
                search: search.trim(),
                filter: filter === 'all' ? undefined : filter,
            });
            setRosterData(res.data || []);
            setTotalPages(res.meta?.last_page || 1);
            setTotalRecords(res.meta?.total || 0);
        } catch (err: any) {
            console.error('Failed to fetch student roster', err);
            showError(err.response?.data?.message || 'Failed to fetch student roster');
        } finally {
            setIsLoadingList(false);
        }
    };

    useEffect(() => {
        if (activeTab === 'view' && eventId && organizerId) {
            fetchRoster();
        }
    }, [activeTab, page, filter, eventId, organizerId]);

    // Fetch settings when the settings tab is active
    const fetchSettings = async () => {
        if (!eventId || !organizerId) return;
        setIsLoadingSettings(true);
        try {
            const data = await rosterClient.getSettings(organizerId, eventId);
            setPrefixes(data.prefixes || []);
            setPriceInput(String(data.ticket_price_paise / 100));
        } catch (err: any) {
            console.error('Failed to fetch settings', err);
            showError('Failed to load roster settings.');
        } finally {
            setIsLoadingSettings(false);
        }
    };

    useEffect(() => {
        if (activeTab === 'pricing' && eventId && organizerId) {
            fetchSettings();
        }
    }, [activeTab, eventId, organizerId]);

    const fetchVerifications = async () => {
        if (!eventId || !organizerId) return;
        setIsLoadingVerifications(true);
        try {
            const res = await rosterClient.allVerifications(organizerId, eventId, {
                page: vPage,
                search: vSearch.trim(),
                status: vStatus === 'all' ? undefined : vStatus,
                source: vSource,
            });
            setVerifications(res.data || []);
            setVTotalPages(res.meta?.last_page || 1);
            setVTotalRecords(res.meta?.total || 0);
        } catch (err: any) {
            console.error('Failed to fetch payment verifications', err);
            showError('Failed to fetch payment verifications');
        } finally {
            setIsLoadingVerifications(false);
        }
    };

    useEffect(() => {
        if (activeTab === 'verification' && eventId && organizerId) {
            fetchVerifications();
        }
    }, [activeTab, vPage, vStatus, vSource, eventId, organizerId]);

    const handleImportVerification = async (vfile: File, source: 'btech' | 'bca') => {
        if (!eventId || !organizerId) return;
        setIsUploadingVerification(true);
        try {
            const res = await rosterClient.importVerifications(organizerId, eventId, vfile, source);
            showSuccess(`Successfully imported ${res.imported} new responses (skipped ${res.skipped} duplicates).`);
            setVPage(1);
            fetchVerifications();
        } catch (err: any) {
            console.error('Failed to import responses', err);
            showError(err.response?.data?.message || 'Failed to import responses.');
        } finally {
            setIsUploadingVerification(false);
        }
    };

    const handleVerifySubmission = async (id: number) => {
        if (!eventId || !organizerId) return;
        try {
            const res = await rosterClient.verifyVerification(organizerId, eventId, id);
            showSuccess(res.message || 'Payment verified and ticket sent!');
            fetchVerifications();
        } catch (err: any) {
            console.error('Failed to verify payment', err);
            showError(err.response?.data?.message || 'Failed to verify payment.');
        }
    };

    const handleRejectSubmission = async (id: number) => {
        const reason = prompt('Enter reason for rejection:');
        if (reason === null) return; // cancelled
        if (!eventId || !organizerId) return;
        try {
            const res = await rosterClient.rejectVerification(organizerId, eventId, id, reason);
            showSuccess(res.message || 'Submission rejected.');
            fetchVerifications();
        } catch (err: any) {
            console.error('Failed to reject submission', err);
            showError(err.response?.data?.message || 'Failed to reject submission.');
        }
    };

    const handleDeleteRosterRecord = async (id: number) => {
        if (!eventId || !organizerId) return;
        try {
            await rosterClient.delete(organizerId, eventId, id);
            showSuccess('Roster entry deleted successfully.');
            fetchRoster();
        } catch (err: any) {
            console.error('Failed to delete roster entry', err);
            showError(err.response?.data?.message || 'Failed to delete roster entry.');
        }
    };

    // Handle search input submission
    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setPage(1);
        fetchRoster();
    };

    // Filter roster data to hide duplicates if enabled
    const displayedRoster = hideClones
        ? rosterData.filter((item, index, self) =>
            self.findIndex(t => t.enrollment_no === item.enrollment_no) === index
          )
        : rosterData;

    // Download spreadsheet template (.xlsx format from backend)
    const handleDownloadTemplate = async () => {
        if (!eventId || !organizerId) return;
        try {
            const blob = await rosterClient.downloadTemplate(organizerId, eventId);
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', 'student_roster_template.xlsx');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        } catch (err: any) {
            console.error('Failed to download template', err);
            showError('Failed to download roster template.');
        }
    };

    // HTML5 Drag and Drop Handlers
    const handleDrag = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        if (e.type === "dragenter" || e.type === "dragover") {
            setIsDragActive(true);
        } else if (e.type === "dragleave") {
            setIsDragActive(false);
        }
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragActive(false);
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            const droppedFile = e.dataTransfer.files[0];
            const fileExt = droppedFile.name.split('.').pop()?.toLowerCase();
            if (['csv', 'xlsx', 'xls'].includes(fileExt || '')) {
                if (droppedFile.size > 50 * 1024 * 1024) {
                    showError('File is too large. Maximum size is 50MB.');
                    return;
                }
                setFile(droppedFile);
                setUploadResult(null);
            } else {
                showError('Invalid file type. Please upload a CSV or Excel file.');
            }
        }
    };

    const handleFileInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files[0]) {
            const selectedFile = e.target.files[0];
            if (selectedFile.size > 50 * 1024 * 1024) {
                showError('File is too large. Maximum size is 50MB.');
                return;
            }
            setFile(selectedFile);
            setUploadResult(null);
        }
    };

    const triggerFileInput = () => {
        fileInputRef.current?.click();
    };

    // Handle upload submit
    const handleUploadSubmit = async () => {
        if (!file || !eventId || !organizerId) return;
        setIsUploading(true);
        setUploadResult(null);
        try {
            const result = await rosterClient.import(organizerId, eventId, file);
            setUploadResult(result);
            showSuccess('Student roster import completed');
            setFile(null);
        } catch (err: any) {
            console.error('Import failed', err);
            showError(err.response?.data?.message || 'Roster import failed. Please verify file format.');
        } finally {
            setIsUploading(false);
        }
    };

    // Add Free Prefix handler
    const handleAddPrefix = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!eventId || !organizerId || !newPrefix.trim()) return;

        setIsSavingPrefix(true);
        try {
            const added = await rosterClient.addPrefix(organizerId, eventId, {
                prefix: newPrefix.trim(),
                label: newPrefixLabel.trim() || undefined,
            });
            showSuccess('Free ticket prefix added successfully.');
            setPrefixes((prev) => [...prev, added]);
            setNewPrefix('');
            setNewPrefixLabel('');
        } catch (err: any) {
            console.error('Failed to add prefix', err);
            showError(err.response?.data?.message || 'Failed to add free prefix.');
        } finally {
            setIsSavingPrefix(false);
        }
    };

    // Delete Free Prefix handler
    const handleDeletePrefix = async (prefixId: number) => {
        if (!eventId || !organizerId) return;

        try {
            await rosterClient.deletePrefix(organizerId, eventId, prefixId);
            showSuccess('Free prefix deleted.');
            setPrefixes((prev) => prev.filter((p) => p.id !== prefixId));
        } catch (err: any) {
            console.error('Failed to delete prefix', err);
            showError('Failed to delete prefix.');
        }
    };

    // Update Price handler
    const handleUpdatePrice = async () => {
        if (!eventId || !organizerId) return;
        const parsedPrice = parseInt(priceInput, 10);
        if (isNaN(parsedPrice) || parsedPrice < 0 || parsedPrice > 10000) {
            showError('Please enter a valid price between ₹0 and ₹10000.');
            return;
        }

        setIsSavingPrice(true);
        try {
            await rosterClient.updatePrice(organizerId, eventId, parsedPrice);
            showSuccess('Ticket price updated successfully.');
        } catch (err: any) {
            console.error('Failed to update price', err);
            showError('Failed to update ticket price.');
        } finally {
            setIsSavingPrice(false);
        }
    };

    const getBatch = (enrollmentNo: string) => {
        return enrollmentNo ? enrollmentNo.substring(0, 3) : '-';
    };

    const renderStatusBadge = (record: StudentRosterRecord) => {
        if (record.scan_count > 0) {
            return (
                <Badge color="blue" variant="dot" size="md">
                    Checked in
                </Badge>
            );
        }
        if (record.has_purchased) {
            return (
                <Badge color="green" variant="dot" size="md">
                    Purchased
                </Badge>
            );
        }
        return (
            <Badge color="red" variant="dot" size="md">
                Not purchased
            </Badge>
        );
    };

    if (isEventLoading) {
        return (
            <PageBody>
                <Group justify="center" py="xl">
                    <Loader size="lg" />
                </Group>
            </PageBody>
        );
    }

    return (
        <PageBody>
            <PageTitle subheading="Manage student details and track ticket purchasing status.">
                Student Roster
            </PageTitle>

            <Tabs value={activeTab} onChange={setActiveTab} mt="md">
                <Tabs.List>
                    <Tabs.Tab value="import" leftSection={<IconUpload size={16} />}>
                        Import
                    </Tabs.Tab>
                    <Tabs.Tab value="view" leftSection={<IconTable size={16} />}>
                        View Roster
                    </Tabs.Tab>
                    <Tabs.Tab value="pricing" leftSection={<IconSettings size={16} />}>
                        Pricing & Prefixes
                    </Tabs.Tab>
                    <Tabs.Tab value="verification" leftSection={<IconCheck size={16} />}>
                        Verification Dashboard
                    </Tabs.Tab>
                </Tabs.List>

                {/* TAB 1: IMPORT */}
                <Tabs.Panel value="import" pt="lg">
                    <Stack gap="lg">
                        <Card withBorder radius="md" p="xl" bg="var(--mantine-color-body)">
                            <Title order={4} mb="xs">Roster File Upload</Title>
                            <Text size="sm" c="dimmed" mb="lg">
                                Upload a spreadsheet (Excel or CSV) containing student records. The system will create new records and update existing ones (as long as they haven't purchased a ticket yet).
                            </Text>

                            <Group mb="xl">
                                <Button
                                    variant="outline"
                                    color="blue"
                                    leftSection={<IconDownload size={16} />}
                                    onClick={handleDownloadTemplate}
                                >
                                    Download Template (.xlsx)
                                </Button>
                            </Group>

                            {/* Custom HTML5 Drag and Drop area */}
                            <Paper
                                withBorder
                                p="xl"
                                radius="md"
                                onDragEnter={handleDrag}
                                onDragOver={handleDrag}
                                onDragLeave={handleDrag}
                                onDrop={handleDrop}
                                onClick={triggerFileInput}
                                style={{
                                    borderStyle: 'dashed',
                                    borderWidth: 2,
                                    borderColor: isDragActive ? 'var(--mantine-color-blue-filled)' : 'var(--mantine-color-default-border)',
                                    backgroundColor: isDragActive ? 'var(--mantine-color-blue-light)' : 'transparent',
                                    cursor: 'pointer',
                                    transition: 'all 0.15s ease',
                                    minHeight: 220,
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                }}
                            >
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    style={{ display: 'none' }}
                                    accept=".csv, .xlsx, .xls, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel"
                                    onChange={handleFileInputChange}
                                />
                                <Stack align="center" gap="sm">
                                    <IconFileSpreadsheet
                                        size={52}
                                        stroke={1.5}
                                        color={isDragActive ? 'var(--mantine-color-blue-filled)' : 'var(--mantine-color-dimmed)'}
                                    />
                                    <div>
                                        <Text size="xl" inline ta="center" fw={600}>
                                            {isDragActive ? 'Drop your file here' : 'Drag & drop Excel or CSV here'}
                                        </Text>
                                        <Text size="sm" ta="center" c="dimmed" inline mt={7}>
                                            or click to browse files (max size 50MB)
                                        </Text>
                                    </div>
                                </Stack>
                            </Paper>

                            {file && (
                                <Paper withBorder p="md" mt="md" radius="md">
                                    <Group justify="space-between">
                                        <div>
                                            <Text fw={500}>{file.name}</Text>
                                            <Text size="xs" c="dimmed">{(file.size / 1024 / 1024).toFixed(2)} MB</Text>
                                        </div>
                                        <Group>
                                            <Button variant="subtle" color="red" onClick={() => setFile(null)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                color="green"
                                                loading={isUploading}
                                                onClick={handleUploadSubmit}
                                                leftSection={<IconCheck size={16} />}
                                            >
                                                Upload Roster
                                            </Button>
                                        </Group>
                                    </Group>
                                </Paper>
                            )}
                        </Card>

                        {/* UPLOAD RESULTS */}
                        {uploadResult && (
                            <Card withBorder radius="md" p="xl">
                                <Title order={4} mb="md" c="green">Import Results Summary</Title>
                                <Group gap="xl" mb="xl">
                                    <Paper p="md" withBorder style={{ flex: 1, textAlign: 'center' }}>
                                        <Text size="xs" c="dimmed" fw={700} tt="uppercase">Imported</Text>
                                        <Text size="xl" fw={700} c="green">{uploadResult.imported}</Text>
                                    </Paper>
                                    <Paper p="md" withBorder style={{ flex: 1, textAlign: 'center' }}>
                                        <Text size="xs" c="dimmed" fw={700} tt="uppercase">Updated</Text>
                                        <Text size="xl" fw={700} c="blue">{uploadResult.updated}</Text>
                                    </Paper>
                                    <Paper p="md" withBorder style={{ flex: 1, textAlign: 'center' }}>
                                        <Text size="xs" c="dimmed" fw={700} tt="uppercase">Skipped (Paid)</Text>
                                        <Text size="xl" fw={700} c="orange">{uploadResult.skipped_purchased}</Text>
                                    </Paper>
                                    <Paper p="md" withBorder style={{ flex: 1, textAlign: 'center' }}>
                                        <Text size="xs" c="dimmed" fw={700} tt="uppercase">Errors</Text>
                                        <Text size="xl" fw={700} c="red">{uploadResult.errors.length}</Text>
                                    </Paper>
                                </Group>

                                {uploadResult.errors.length > 0 && (
                                    <div>
                                        <Text fw={600} mb="xs" c="red">Processing Errors ({uploadResult.errors.length})</Text>
                                        <Table withTableBorder withColumnBorders verticalSpacing="sm">
                                            <Table.Thead>
                                                <Table.Tr>
                                                    <Table.Th style={{ width: 80 }}>Row</Table.Th>
                                                    <Table.Th style={{ width: 180 }}>Enrollment No</Table.Th>
                                                    <Table.Th>Reason</Table.Th>
                                                </Table.Tr>
                                            </Table.Thead>
                                            <Table.Tbody>
                                                {uploadResult.errors.map((err, idx) => (
                                                    <Table.Tr key={idx}>
                                                        <Table.Td>{err.row}</Table.Td>
                                                        <Table.Td>{err.enrollment_no || <Text size="sm" c="dimmed" fs="italic">empty</Text>}</Table.Td>
                                                        <Table.Td c="red">{err.reason}</Table.Td>
                                                    </Table.Tr>
                                                ))}
                                            </Table.Tbody>
                                        </Table>
                                    </div>
                                )}
                            </Card>
                        )}
                    </Stack>
                </Tabs.Panel>

                {/* TAB 2: ROSTER VIEW */}
                <Tabs.Panel value="view" pt="lg">
                    <Stack gap="md">
                        {/* Search & Filter bar */}
                        <form onSubmit={handleSearchSubmit}>
                            <Group align="flex-end" justify="space-between">
                                <Group align="flex-end" style={{ flex: 1 }}>
                                    <TextInput
                                        placeholder="Search by name or enrollment number..."
                                        label="Search"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        leftSection={<IconSearch size={16} />}
                                        style={{ flex: 1 }}
                                    />
                                    <Select
                                        label="Status Filter"
                                        value={filter}
                                        onChange={(val) => {
                                            setFilter(val || 'all');
                                            setPage(1);
                                        }}
                                        data={[
                                            { value: 'all', label: 'All Students' },
                                            { value: 'purchased', label: 'Purchased' },
                                            { value: 'not_purchased', label: 'Not Purchased' },
                                            { value: 'checked_in', label: 'Checked In' },
                                        ]}
                                        style={{ width: 200 }}
                                    />
                                    <Button type="submit" leftSection={<IconSearch size={16} />} color="blue">
                                        Search
                                    </Button>
                                    <Button
                                        variant="default"
                                        onClick={() => {
                                            setSearch('');
                                            setFilter('all');
                                            setPage(1);
                                            fetchRoster();
                                        }}
                                        title="Reset filters"
                                    >
                                        <IconRefresh size={16} />
                                    </Button>
                                </Group>
                                <Switch
                                    label="Hide Duplicate Records"
                                    checked={hideClones}
                                    onChange={(event) => setHideClones(event.currentTarget.checked)}
                                    size="md"
                                    color="blue"
                                    style={{ marginBottom: 8 }}
                                />
                            </Group>
                        </form>

                        {/* Roster Table */}
                        {isLoadingList ? (
                            <Group justify="center" py="xl">
                                <Loader size="md" />
                            </Group>
                        ) : rosterData.length === 0 ? (
                            <Alert color="blue" title="No Roster Data" icon={<IconInfoCircle size={16} />}>
                                No student records found. Upload a spreadsheet to populate the roster.
                            </Alert>
                        ) : (
                            <>
                                <Paper withBorder radius="md" style={{ overflow: 'hidden' }}>
                                    <Table verticalSpacing="sm" horizontalSpacing="md" striped highlightOnHover>
                                        <Table.Thead>
                                            <Table.Tr>
                                                <Table.Th>Enrollment No</Table.Th>
                                                <Table.Th>Name</Table.Th>
                                                <Table.Th>Email</Table.Th>
                                                <Table.Th>Batch</Table.Th>
                                                <Table.Th>Status</Table.Th>
                                                <Table.Th>Scans</Table.Th>
                                                <Table.Th style={{ width: 100 }}>Actions</Table.Th>
                                            </Table.Tr>
                                        </Table.Thead>
                                        <Table.Tbody>
                                            {displayedRoster.map((record, idx) => (
                                                <Table.Tr key={`${record.enrollment_no}-${idx}`}>
                                                    <Table.Td fw={500}>{record.enrollment_no}</Table.Td>
                                                    <Table.Td>{record.name || '-'}</Table.Td>
                                                    <Table.Td>{record.email || '-'}</Table.Td>
                                                    <Table.Td>
                                                        <Badge color="gray" variant="light">
                                                            {getBatch(record.enrollment_no)}
                                                        </Badge>
                                                    </Table.Td>
                                                    <Table.Td>{renderStatusBadge(record)}</Table.Td>
                                                    <Table.Td>
                                                        {record.scan_count > 0 ? (
                                                            <Tooltip
                                                                label={record.last_scanned_at ? `Last scan: ${prettyDate(record.last_scanned_at, event?.timezone || 'UTC')}` : ''}
                                                                withArrow
                                                            >
                                                                <Badge color="blue" style={{ cursor: 'pointer' }}>
                                                                    {record.scan_count} {record.scan_count === 1 ? 'Scan' : 'Scans'}
                                                                </Badge>
                                                            </Tooltip>
                                                        ) : (
                                                            <Text size="sm" c="dimmed">0</Text>
                                                        )}
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Button
                                                            size="xs"
                                                            color="red"
                                                            variant="light"
                                                            leftSection={<IconTrash size={14} />}
                                                            onClick={() => handleDeleteRosterRecord(record.id)}
                                                        >
                                                            Delete
                                                        </Button>
                                                    </Table.Td>
                                                </Table.Tr>
                                            ))}
                                        </Table.Tbody>
                                    </Table>
                                </Paper>

                                <Group justify="space-between" mt="md">
                                    <Text size="sm" c="dimmed">
                                        Showing {displayedRoster.length} of {totalRecords} records
                                    </Text>
                                    <Pagination
                                        value={page}
                                        onChange={setPage}
                                        total={totalPages}
                                    />
                                </Group>
                            </>
                        )}
                    </Stack>
                </Tabs.Panel>

                {/* TAB 3: PRICING & PREFIXES */}
                <Tabs.Panel value="pricing" pt="lg">
                    {isLoadingSettings ? (
                        <Group justify="center" py="xl">
                            <Loader size="md" />
                        </Group>
                    ) : (
                        <Stack gap="xl">
                            <Group align="flex-start" grow>
                                {/* BLOCK A: PREFIX MANAGER */}
                                <Card withBorder radius="md" p="xl" bg="var(--mantine-color-body)">
                                    <Stack gap="md">
                                        <Group gap="xs">
                                            <ThemeIcon size="md" radius="sm" color="indigo" variant="light">
                                                <IconSettings size={18} />
                                            </ThemeIcon>
                                            <Title order={4}>Free Ticket Roll Number Prefixes</Title>
                                        </Group>
                                        <Text size="sm" c="dimmed">
                                            Students whose enrollment numbers start with these prefixes will receive free entry. 
                                            System verifies that the roll number is in the roster first.
                                        </Text>

                                        <Title order={5} mt="sm">Current Free Prefixes</Title>
                                        {prefixes.length === 0 ? (
                                            <Text size="sm" c="dimmed" fs="italic">
                                                No free prefixes defined. All verified students will be charged standard ticket price.
                                            </Text>
                                        ) : (
                                            <Table withTableBorder withColumnBorders verticalSpacing="xs">
                                                <Table.Thead>
                                                    <Table.Tr>
                                                        <Table.Th>Prefix</Table.Th>
                                                        <Table.Th>Label</Table.Th>
                                                        <Table.Th style={{ width: 100 }}>Action</Table.Th>
                                                    </Table.Tr>
                                                </Table.Thead>
                                                <Table.Tbody>
                                                    {prefixes.map((pref) => (
                                                        <Table.Tr key={pref.id}>
                                                            <Table.Td fw={600} style={{ fontFamily: 'monospace' }}>
                                                                {pref.prefix}
                                                            </Table.Td>
                                                            <Table.Td>{pref.label || '-'}</Table.Td>
                                                            <Table.Td>
                                                                <Button
                                                                    size="xs"
                                                                    color="red"
                                                                    variant="subtle"
                                                                    onClick={() => handleDeletePrefix(pref.id)}
                                                                >
                                                                    Remove
                                                                </Button>
                                                            </Table.Td>
                                                        </Table.Tr>
                                                    ))}
                                                </Table.Tbody>
                                            </Table>
                                        )}

                                        <Card withBorder p="md" mt="md" radius="sm" bg="var(--mantine-color-gray-0)">
                                            <form onSubmit={handleAddPrefix}>
                                                <Stack gap="xs">
                                                    <Text fw={600} size="sm">Add New Free Prefix</Text>
                                                    <Group align="flex-end" grow>
                                                        <TextInput
                                                            label="Roll No Prefix"
                                                            placeholder="e.g. 220"
                                                            required
                                                            value={newPrefix}
                                                            onChange={(e) => setNewPrefix(e.target.value)}
                                                        />
                                                        <TextInput
                                                            label="Label"
                                                            placeholder="e.g. Final Year"
                                                            value={newPrefixLabel}
                                                            onChange={(e) => setNewPrefixLabel(e.target.value)}
                                                        />
                                                    </Group>
                                                    <Button
                                                        type="submit"
                                                        color="blue"
                                                        size="sm"
                                                        loading={isSavingPrefix}
                                                        mt="xs"
                                                    >
                                                        Add Free Prefix
                                                    </Button>
                                                </Stack>
                                            </form>
                                        </Card>
                                    </Stack>
                                </Card>

                                {/* BLOCK B: TICKET PRICE MANAGER */}
                                <Card withBorder radius="md" p="xl" bg="var(--mantine-color-body)" style={{ maxHeight: 'fit-content' }}>
                                    <Stack gap="md">
                                        <Group gap="xs">
                                            <ThemeIcon size="md" radius="sm" color="teal" variant="light">
                                                <IconCoin size={18} stroke={1.5} />
                                            </ThemeIcon>
                                            <Title order={4}>Ticket Price Manager</Title>
                                        </Group>
                                        <Text size="sm" c="dimmed">
                                            Applied to all students in the roster NOT matched by any of the free prefixes.
                                        </Text>

                                        <TextInput
                                            label="Ticket Price (₹)"
                                            placeholder="200"
                                            required
                                            value={priceInput}
                                            onChange={(e) => setPriceInput(e.target.value)}
                                            size="md"
                                            mt="sm"
                                        />

                                        <Button
                                            color="teal"
                                            loading={isSavingPrice}
                                            onClick={handleUpdatePrice}
                                            size="md"
                                            fullWidth
                                        >
                                            Update Price
                                        </Button>

                                        <Alert color="orange" icon={<IconInfoCircle size={16} />} title="Important Note" mt="md">
                                            Price changes apply to new lookup sessions only. Students currently in checkout or already issued tickets are not affected.
                                        </Alert>
                                    </Stack>
                                </Card>
                            </Group>
                        </Stack>
                    )}
                </Tabs.Panel>

                {/* TAB 4: VERIFICATION DASHBOARD */}
                <Tabs.Panel value="verification" pt="lg">
                    <Stack gap="lg">
                        {/* Import cards */}
                        <Group align="flex-start" grow>
                            <Card withBorder radius="md" p="xl" bg="var(--mantine-color-body)">
                                <Stack gap="md">
                                    <Group gap="xs">
                                        <ThemeIcon size="md" radius="sm" color="indigo" variant="light">
                                            <IconUpload size={18} />
                                        </ThemeIcon>
                                        <Title order={4}>Upload B.Tech Form Responses</Title>
                                    </Group>
                                    <Text size="sm" c="dimmed">
                                        Upload the CSV/Excel sheet downloaded from the B.Tech Google Form.
                                    </Text>
                                    <input
                                        type="file"
                                        ref={btechFileInputRef}
                                        style={{ display: 'none' }}
                                        accept=".csv,.xlsx,.xls"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) handleImportVerification(file, 'btech');
                                        }}
                                    />
                                    <Button
                                        color="indigo"
                                        loading={isUploadingVerification}
                                        onClick={() => btechFileInputRef.current?.click()}
                                    >
                                        Select and Upload B.Tech File
                                    </Button>
                                </Stack>
                            </Card>

                            <Card withBorder radius="md" p="xl" bg="var(--mantine-color-body)">
                                <Stack gap="md">
                                    <Group gap="xs">
                                        <ThemeIcon size="md" radius="sm" color="violet" variant="light">
                                            <IconUpload size={18} />
                                        </ThemeIcon>
                                        <Title order={4}>Upload BCA Form Responses</Title>
                                    </Group>
                                    <Text size="sm" c="dimmed">
                                        Upload the CSV/Excel sheet downloaded from the BCA Google Form.
                                    </Text>
                                    <input
                                        type="file"
                                        ref={bcaFileInputRef}
                                        style={{ display: 'none' }}
                                        accept=".csv,.xlsx,.xls"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) handleImportVerification(file, 'bca');
                                        }}
                                    />
                                    <Button
                                        color="violet"
                                        loading={isUploadingVerification}
                                        onClick={() => bcaFileInputRef.current?.click()}
                                    >
                                        Select and Upload BCA File
                                    </Button>
                                </Stack>
                            </Card>
                        </Group>

                        {/* Search and Filters */}
                        <Card withBorder p="md" radius="md">
                            <Group justify="space-between" align="center">
                                <Group gap="md">
                                    <SegmentedControl
                                        value={vSource}
                                        onChange={setVSource}
                                        data={[
                                            { label: 'B.Tech Submissions', value: 'btech' },
                                            { label: 'BCA Submissions', value: 'bca' },
                                        ]}
                                        color="blue"
                                    />
                                    <Select
                                        placeholder="Filter by Status"
                                        value={vStatus}
                                        onChange={(val) => {
                                            setVStatus(val || 'PENDING');
                                            setVPage(1);
                                        }}
                                        data={[
                                            { label: 'Pending Verification', value: 'PENDING' },
                                            { label: 'Verified', value: 'VERIFIED' },
                                            { label: 'Rejected', value: 'REJECTED' },
                                            { label: 'All Statuses', value: 'all' },
                                        ]}
                                        style={{ width: 200 }}
                                    />
                                </Group>
                                <form onSubmit={(e) => {
                                    e.preventDefault();
                                    setVPage(1);
                                    fetchVerifications();
                                }}>
                                    <Group gap="xs">
                                        <TextInput
                                            placeholder="Search by name, roll, txn..."
                                            value={vSearch}
                                            onChange={(e) => setVSearch(e.target.value)}
                                            leftSection={<IconSearch size={16} />}
                                            style={{ width: 250 }}
                                        />
                                        <Button type="submit" variant="light" color="blue">
                                            Search
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="subtle"
                                            color="gray"
                                            onClick={() => {
                                                setVSearch('');
                                                setVPage(1);
                                                setTimeout(() => fetchVerifications(), 50);
                                            }}
                                        >
                                            Clear
                                        </Button>
                                    </Group>
                                </form>
                            </Group>
                        </Card>

                        {/* Submissions List */}
                        {isLoadingVerifications ? (
                            <Group justify="center" py="xl">
                                <Loader size="md" />
                            </Group>
                        ) : verifications.length === 0 ? (
                            <Card withBorder p="xl" radius="md">
                                <Stack align="center" gap="xs">
                                    <ThemeIcon size="xl" radius="md" color="gray" variant="light">
                                        <IconTable size={24} />
                                    </ThemeIcon>
                                    <Text fw={600}>No Submissions Found</Text>
                                    <Text size="sm" c="dimmed">
                                        There are no responses matching the selected filters. Upload responses to begin.
                                    </Text>
                                </Stack>
                            </Card>
                        ) : (
                            <>
                                <Table withTableBorder withColumnBorders verticalSpacing="sm" horizontalSpacing="md">
                                    <Table.Thead bg="var(--mantine-color-gray-1)">
                                        <Table.Tr>
                                            <Table.Th>Student & Email</Table.Th>
                                            <Table.Th>Roll Number</Table.Th>
                                            <Table.Th>Transaction Info</Table.Th>
                                            <Table.Th>Roster Validation</Table.Th>
                                            <Table.Th>Status</Table.Th>
                                            <Table.Th style={{ width: 200 }}>Actions</Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        {verifications.map((record) => (
                                            <Table.Tr key={record.id}>
                                                <Table.Td>
                                                    <Stack gap={2}>
                                                        <Text fw={600} size="sm">{record.name}</Text>
                                                        <Text size="xs" c="dimmed">{record.email}</Text>
                                                        {record.phone && (
                                                            <Text size="xs" c="dimmed">Ph: {record.phone}</Text>
                                                        )}
                                                    </Stack>
                                                </Table.Td>
                                                <Table.Td>
                                                    <Code>{record.enrollment_no}</Code>
                                                </Table.Td>
                                                <Table.Td>
                                                    <Stack gap={2}>
                                                        <Text size="sm">
                                                            Txn ID: <Text span fw={600}>{record.transaction_id || 'N/A'}</Text>
                                                        </Text>
                                                        <Text size="xs" c="dimmed">Method: {record.payment_method}</Text>
                                                        {record.screenshot_url && (
                                                            <Button
                                                                component="a"
                                                                href={record.screenshot_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                size="xs"
                                                                variant="light"
                                                                color="blue"
                                                                mt={4}
                                                                style={{ width: 'fit-content' }}
                                                            >
                                                                View Screenshot Proof
                                                            </Button>
                                                        )}
                                                    </Stack>
                                                </Table.Td>
                                                <Table.Td>
                                                    <Stack gap={4}>
                                                        <Group gap={6}>
                                                            <Badge
                                                                color={record.enrollment_valid.includes('VALID') ? 'green' : 'red'}
                                                                variant="light"
                                                            >
                                                                {record.enrollment_valid}
                                                            </Badge>
                                                            <Badge
                                                                color={record.name_match.includes('MATCHES') ? 'green' : 'yellow'}
                                                                variant="light"
                                                            >
                                                                {record.name_match}
                                                            </Badge>
                                                        </Group>
                                                        {record.already_purchased && (
                                                            <Badge color="teal" variant="filled">
                                                                Ticket Already Issued
                                                            </Badge>
                                                        )}
                                                        {record.duplicate_txn && (
                                                            <Badge color="red" variant="filled">
                                                                ⚠️ DUPLICATE TXN ID
                                                            </Badge>
                                                        )}
                                                    </Stack>
                                                </Table.Td>
                                                <Table.Td>
                                                    {record.status === 'PENDING' && (
                                                        <Badge color="yellow" variant="dot">Pending</Badge>
                                                    )}
                                                    {record.status === 'VERIFIED' && (
                                                        <Badge color="green" variant="filled">Verified</Badge>
                                                    )}
                                                    {record.status === 'REJECTED' && (
                                                        <Tooltip label={record.notes || ''}>
                                                            <Badge color="red" variant="filled" style={{ cursor: 'pointer' }}>
                                                                Rejected
                                                            </Badge>
                                                        </Tooltip>
                                                    )}
                                                </Table.Td>
                                                <Table.Td>
                                                    {record.status === 'PENDING' ? (
                                                        <Group gap="xs">
                                                            <Button
                                                                size="xs"
                                                                color="green"
                                                                leftSection={<IconCheck size={14} />}
                                                                disabled={!record.enrollment_valid.includes('VALID') || record.already_purchased}
                                                                onClick={() => handleVerifySubmission(record.id)}
                                                            >
                                                                Verify
                                                            </Button>
                                                            <Button
                                                                size="xs"
                                                                color="red"
                                                                variant="outline"
                                                                leftSection={<IconX size={14} />}
                                                                onClick={() => handleRejectSubmission(record.id)}
                                                            >
                                                                Reject
                                                            </Button>
                                                        </Group>
                                                    ) : (
                                                        <Text size="xs" c="dimmed">
                                                            {record.notes || 'Processed'}
                                                        </Text>
                                                    )}
                                                </Table.Td>
                                            </Table.Tr>
                                        ))}
                                    </Table.Tbody>
                                </Table>

                                <Group justify="space-between" mt="md">
                                    <Text size="sm" c="dimmed">
                                        Showing {verifications.length} of {vTotalRecords} submissions
                                    </Text>
                                    <Pagination
                                        value={vPage}
                                        onChange={setVPage}
                                        total={vTotalPages}
                                    />
                                </Group>
                            </>
                        )}
                    </Stack>
                </Tabs.Panel>
            </Tabs>
        </PageBody>
    );
};

export default StudentRoster;
