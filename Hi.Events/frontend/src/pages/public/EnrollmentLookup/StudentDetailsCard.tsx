import React from 'react';
import { Card, Avatar, Text, Group, Stack } from '@mantine/core';
import { IconSchool, IconCalendar } from '@tabler/icons-react';

interface StudentDetailsCardProps {
  name: string;
  enrollmentNo: string;
  branch?: string | null;
  year?: string | number | null;
}

export const StudentDetailsCard: React.FC<StudentDetailsCardProps> = ({
  name,
  enrollmentNo,
  branch,
  year,
}) => {
  // Extract initials
  const initials = name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .substring(0, 2)
    .toUpperCase();

  return (
    <Card shadow="sm" padding="lg" radius="md" withBorder>
      <Group gap="md" align="flex-start" wrap="nowrap">
        <Avatar
          size="xl"
          radius="md"
          color="indigo"
          styles={{
            placeholder: {
              fontWeight: 700,
              fontSize: '22px',
            }
          }}
        >
          {initials || 'ST'}
        </Avatar>

        <Stack gap="xs" style={{ flex: 1 }}>
          <div>
            <Text size="lg" fw={700} c="dark" style={{ lineHeight: 1.2 }}>
              {name}
            </Text>
            <Text size="sm" c="dimmed" fw={500} mt={3}>
              Enrollment No: {enrollmentNo}
            </Text>
          </div>

          <Group gap="md" wrap="wrap">
            {branch && (
              <Group gap={4} wrap="nowrap">
                <IconSchool size={16} style={{ color: 'var(--mantine-color-indigo-6)' }} />
                <Text size="sm" fw={500}>
                  {branch}
                </Text>
              </Group>
            )}

            {year && (
              <Group gap={4} wrap="nowrap">
                <IconCalendar size={16} style={{ color: 'var(--mantine-color-indigo-6)' }} />
                <Text size="sm" fw={500}>
                  Year {year}
                </Text>
              </Group>
            )}
          </Group>
        </Stack>
      </Group>
    </Card>
  );
};

export default StudentDetailsCard;
