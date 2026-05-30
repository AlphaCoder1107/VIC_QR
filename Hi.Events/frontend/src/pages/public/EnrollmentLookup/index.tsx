import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router';
import {
  Container,
  Paper,
  TextInput,
  Button,
  Title,
  Text,
  Stack,
  Loader,
  Alert,
  Group,
  ThemeIcon,
  ActionIcon
} from '@mantine/core';
import {
  IconSearch,
  IconArrowLeft,
  IconMail,
  IconAlertCircle,
  IconCheck,
  IconInfoCircle
} from '@tabler/icons-react';
import { publicApi } from '../../../api/public-client';
import { getConfig } from '../../../utilites/config';
import StudentDetailsCard from './StudentDetailsCard';
import PricingBadge from './PricingBadge';
import { RazorpayCheckout } from '../../../components/enrollment/RazorpayCheckout';

type UIState = 'IDLE' | 'LOADING' | 'FOUND_FREE' | 'FOUND_PAID' | 'ISSUED' | 'ALREADY_DONE' | 'NOT_FOUND' | 'ERROR';

interface StudentRosterResponse {
  enrollment_no: string;
  student: {
    name: string;
    branch: string | null;
    year: string | number | null;
    email: string | null;
    editable_email: string | null;
  };
  purchase_state: {
    has_purchased: boolean;
    message: string;
  };
  pricing: {
    roll_number: number | null;
    prefix_digits: number;
    rule_name: string | null;
    price_paise: number | null;
    is_free: boolean;
    priority: number | null;
  };
}

export const EnrollmentLookup: React.FC = () => {
  const { eventId: routeEventId } = useParams<{ eventId: string }>();
  const configEventId = getConfig('VITE_EVENT_ID');
  const eventId = routeEventId ? parseInt(routeEventId, 10) : (configEventId ? parseInt(configEventId as string, 10) : null);

  const [enrollmentNo, setEnrollmentNo] = useState('');
  const [uiState, setUiState] = useState<UIState>('IDLE');
  const [studentData, setStudentData] = useState<StudentRosterResponse | null>(null);
  const [emailInput, setEmailInput] = useState('');
  const [isUpdatingEmail, setIsUpdatingEmail] = useState(false);
  const [isClaimingFree, setIsClaimingFree] = useState(false);
  const [isResending, setIsResending] = useState(false);
  const [resendSuccess, setResendSuccess] = useState(false);
  const [checkoutActive, setCheckoutActive] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');

  // Handle student lookup
  const handleLookup = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!enrollmentNo.trim()) return;

    if (!eventId) {
      setErrorMessage('Event configuration is missing.');
      setUiState('ERROR');
      return;
    }

    setUiState('LOADING');
    setErrorMessage('');

    try {
      const response = await publicApi.post<StudentRosterResponse>(
        `events/${eventId}/enrollment-lookup`,
        { enrollment_no: enrollmentNo.trim() }
      );

      const data = response.data;
      setStudentData(data);
      setEnrollmentNo(data.enrollment_no);
      setEmailInput(data.student.editable_email || '');

      if (data.purchase_state.has_purchased) {
        setUiState('ALREADY_DONE');
      } else if (data.pricing.is_free) {
        setUiState('FOUND_FREE');
      } else {
        setUiState('FOUND_PAID');
      }
    } catch (error: any) {
      if (error.response && error.response.status === 404) {
        setUiState('NOT_FOUND');
      } else {
        setErrorMessage(error.response?.data?.message || 'Something went wrong. Please try again.');
        setUiState('ERROR');
      }
    }
  };

  // Sync edited email to backend
  const handleEmailUpdate = async (newEmail: string): Promise<boolean> => {
    const trimmedEmail = newEmail.trim();
    if (!trimmedEmail || !studentData || trimmedEmail === studentData.student.editable_email) return true;

    setIsUpdatingEmail(true);
    try {
      await publicApi.patch(`events/${eventId}/enrollment/update-email`, {
        enrollment_no: enrollmentNo.trim(),
        email: trimmedEmail,
      });

      // Update local state
      setStudentData((prev) => {
        if (!prev) return null;
        return {
          ...prev,
          student: {
            ...prev.student,
            editable_email: trimmedEmail,
          },
        };
      });

      return true;
    } catch (e: any) {
      console.error('Email update failed:', e);
      setErrorMessage(e.response?.data?.message || 'Failed to update ticket recipient email.');
      setUiState('ERROR');
      return false;
    } finally {
      setIsUpdatingEmail(false);
    }
  };

  // Claim free ticket path
  const handleClaimFree = async () => {
    if (!eventId || !studentData) return;

    setIsClaimingFree(true);
    setErrorMessage('');

    try {
      // Ensure email is updated on backend first
      const emailUpdated = await handleEmailUpdate(emailInput);
      if (!emailUpdated) {
        return;
      }

      await publicApi.post(`events/${eventId}/enrollment/claim-free`, {
        enrollment_no: enrollmentNo.trim(),
      });

      setUiState('ISSUED');
    } catch (error: any) {
      setErrorMessage(error.response?.data?.message || 'Failed to claim complimentary ticket. Please try again.');
      setUiState('ERROR');
    } finally {
      setIsClaimingFree(false);
    }
  };

  // Trigger paid checkout
  const handleProceedToPayment = async () => {
    // Save email first
    const emailUpdated = await handleEmailUpdate(emailInput);
    if (!emailUpdated) {
      return;
    }

    setCheckoutActive(true);
  };

  // Resend ticket path
  const handleResendTicket = async () => {
    if (!eventId) return;

    setIsResending(true);
    setResendSuccess(false);
    setErrorMessage('');

    try {
      await publicApi.post(`events/${eventId}/enrollment/resend-ticket`, {
        enrollment_no: enrollmentNo.trim(),
      });
      setResendSuccess(true);
    } catch (error: any) {
      setErrorMessage(error.response?.data?.message || 'Failed to resend ticket. Please contact support.');
    } finally {
      setIsResending(false);
    }
  };

  // Reset to idle lookup state
  const resetToIdle = () => {
    setEnrollmentNo('');
    setStudentData(null);
    setEmailInput('');
    setCheckoutActive(false);
    setResendSuccess(false);
    setUiState('IDLE');
  };

  return (
    <Container size="xs" py={40} style={{ minHeight: '85vh', display: 'flex', alignItems: 'center' }}>
      <Paper radius="md" p="xl" withBorder style={{ width: '100%', position: 'relative', overflow: 'hidden' }}>
        
        {/* Subtle top decoration */}
        <div style={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          height: '5px',
          background: 'linear-gradient(90deg, var(--mantine-color-indigo-6) 0%, var(--mantine-color-violet-6) 100%)'
        }} />

        {/* Back navigation icon */}
        {uiState !== 'IDLE' && uiState !== 'LOADING' && !checkoutActive && (
          <ActionIcon
            variant="subtle"
            color="gray"
            onClick={resetToIdle}
            mb="md"
          >
            <IconArrowLeft size={18} />
          </ActionIcon>
        )}

        <Stack gap="xl">
          {/* Header Title */}
          {!checkoutActive && (
            <div style={{ textAlign: 'center' }}>
              <Title order={2} fw={800} style={{ letterSpacing: '-0.5px' }}>
                VIC Farewell Portal
              </Title>
              <Text size="sm" c="dimmed" mt={4}>
                Student Enrollment Lookup & Verification
              </Text>
            </div>
          )}

          {/* Render States */}
          {uiState === 'IDLE' && (
            <form onSubmit={handleLookup}>
              <Stack gap="md">
                <TextInput
                  label="Enrollment Number"
                  placeholder="e.g. 0101CS201024"
                  size="md"
                  value={enrollmentNo}
                  onChange={(e) => setEnrollmentNo(e.target.value)}
                  leftSection={<IconSearch size={16} />}
                  required
                  autoFocus
                />
                <Button
                  type="submit"
                  size="md"
                  variant="gradient"
                  gradient={{ from: 'indigo', to: 'violet' }}
                  fullWidth
                >
                  Verify Enrollment
                </Button>
              </Stack>
            </form>
          )}

          {uiState === 'LOADING' && (
            <Stack align="center" py="xl" gap="md">
              <Loader color="indigo" size="md" type="dots" />
              <Text size="sm" fw={500} c="dimmed">
                Verifying enrollment details...
              </Text>
            </Stack>
          )}

          {uiState === 'FOUND_FREE' && studentData && (
            <Stack gap="md">
              <StudentDetailsCard
                name={studentData.student.name}
                enrollmentNo={studentData.enrollment_no}
                branch={studentData.student.branch}
                year={studentData.student.year}
              />
              
              <Group justify="center">
                <PricingBadge
                  isFree={studentData.pricing.is_free}
                  pricePaise={studentData.pricing.price_paise}
                  ruleName={studentData.pricing.rule_name}
                />
              </Group>

              <TextInput
                label="Ticket Recipient Email"
                placeholder="email@example.com"
                size="md"
                value={emailInput}
                onChange={(e) => setEmailInput(e.target.value)}
                onBlur={(e) => handleEmailUpdate(e.target.value)}
                leftSection={<IconMail size={16} />}
                description="Make sure you have access to this email address to receive your ticket."
                disabled={isClaimingFree || isUpdatingEmail}
                required
              />

              <Button
                size="md"
                variant="gradient"
                gradient={{ from: 'teal', to: 'green' }}
                onClick={handleClaimFree}
                loading={isClaimingFree || isUpdatingEmail}
                fullWidth
              >
                Confirm & Get My Ticket
              </Button>
            </Stack>
          )}

          {uiState === 'FOUND_PAID' && studentData && (
            <Stack gap="md">
              {!checkoutActive ? (
                <>
                  <StudentDetailsCard
                    name={studentData.student.name}
                    enrollmentNo={studentData.enrollment_no}
                    branch={studentData.student.branch}
                    year={studentData.student.year}
                  />

                  <Group justify="center">
                    <PricingBadge
                      isFree={studentData.pricing.is_free}
                      pricePaise={studentData.pricing.price_paise}
                      ruleName={studentData.pricing.rule_name}
                    />
                  </Group>

                  <TextInput
                    label="Ticket Recipient Email"
                    placeholder="email@example.com"
                    size="md"
                    value={emailInput}
                    onChange={(e) => setEmailInput(e.target.value)}
                    onBlur={(e) => handleEmailUpdate(e.target.value)}
                    leftSection={<IconMail size={16} />}
                    description="Make sure you have access to this email address to receive your ticket."
                    disabled={isUpdatingEmail}
                    required
                  />

                  <Button
                    size="md"
                    variant="gradient"
                    gradient={{ from: 'indigo', to: 'violet' }}
                    onClick={handleProceedToPayment}
                    loading={isUpdatingEmail}
                    fullWidth
                  >
                    Pay & Get Ticket
                  </Button>
                </>
              ) : (
                <Stack gap="md" align="center" py="md">
                  <RazorpayCheckout
                    enrollmentNo={enrollmentNo.trim()}
                    eventId={eventId!}
                    studentName={studentData.student.name}
                    studentEmail={emailInput.trim()}
                    onSuccess={() => {
                      setCheckoutActive(false);
                      setUiState('ISSUED');
                    }}
                    onFailure={() => {
                      setCheckoutActive(false);
                    }}
                  />
                  <Button
                    variant="subtle"
                    color="gray"
                    onClick={() => setCheckoutActive(false)}
                    fullWidth
                  >
                    Cancel Payment
                  </Button>
                </Stack>
              )}
            </Stack>
          )}

          {uiState === 'ISSUED' && (
            <Stack gap="md" align="center" py="lg">
              <ThemeIcon size={64} radius="xl" color="teal" variant="light">
                <IconCheck size={36} />
              </ThemeIcon>
              <Title order={3} fw={800}>
                Ticket Issued!
              </Title>
              <Text size="md" c="dimmed" ta="center">
                Your ticket has been generated and sent to:
                <br />
                <Text span fw={700} c="dark">
                  {emailInput}
                </Text>
              </Text>
              <Text size="sm" c="dimmed" ta="center">
                Check your inbox (including promotions/spam folders). Present the ticket QR code at the entry gate.
              </Text>
              <Button onClick={resetToIdle} mt="md" fullWidth>
                Back to Portal
              </Button>
            </Stack>
          )}

          {uiState === 'ALREADY_DONE' && studentData && (
            <Stack gap="md">
              <StudentDetailsCard
                name={studentData.student.name}
                enrollmentNo={studentData.enrollment_no}
                branch={studentData.student.branch}
                year={studentData.student.year}
              />

              <Alert
                color="blue"
                title="Ticket Already Issued"
                icon={<IconInfoCircle size={16} />}
              >
                A ticket was already claimed or purchased for this enrollment. It has been sent to your registered email: <strong>{studentData.student.email}</strong>.
              </Alert>

              {resendSuccess && (
                <Alert
                  color="green"
                  title="Resent Successfully"
                  icon={<IconCheck size={16} />}
                >
                  The ticket email was resent. Please check your inbox.
                </Alert>
              )}

              {errorMessage && (
                <Alert
                  color="red"
                  title="Resend Failed"
                  icon={<IconAlertCircle size={16} />}
                >
                  {errorMessage}
                </Alert>
              )}

              <Stack gap="xs">
                <Button
                  size="md"
                  color="blue"
                  variant="outline"
                  onClick={handleResendTicket}
                  loading={isResending}
                  fullWidth
                >
                  Resend Ticket Email
                </Button>
                <Button
                  size="md"
                  variant="subtle"
                  color="gray"
                  onClick={resetToIdle}
                  disabled={isResending}
                  fullWidth
                >
                  Verify Another Enrollment
                </Button>
              </Stack>
            </Stack>
          )}

          {uiState === 'NOT_FOUND' && (
            <Stack gap="md" align="center" py="lg">
              <ThemeIcon size={64} radius="xl" color="red" variant="light">
                <IconAlertCircle size={36} />
              </ThemeIcon>
              <Title order={3} fw={800}>
                Not Registered
              </Title>
              <Text size="md" c="dimmed" ta="center">
                Enrollment number <Text span fw={700}>{enrollmentNo}</Text> is not found in the student roster.
              </Text>
              <Text size="sm" c="dimmed" ta="center">
                If you are a student, please contact the VIC core committee to get registered.
              </Text>
              <Button onClick={resetToIdle} mt="md" fullWidth>
                Try Again
              </Button>
            </Stack>
          )}

          {uiState === 'ERROR' && (
            <Stack gap="md" align="center" py="lg">
              <ThemeIcon size={64} radius="xl" color="red" variant="light">
                <IconAlertCircle size={36} />
              </ThemeIcon>
              <Title order={3} fw={800}>
                Verification Error
              </Title>
              <Text size="md" c="red" ta="center">
                {errorMessage || 'An error occurred during verification.'}
              </Text>
              <Button onClick={resetToIdle} mt="md" fullWidth>
                Try Again
              </Button>
            </Stack>
          )}

        </Stack>
      </Paper>
    </Container>
  );
};

export default EnrollmentLookup;
