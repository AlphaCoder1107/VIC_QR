import React from 'react';
import { Badge, ThemeIcon } from '@mantine/core';
import { IconGift, IconTicket } from '@tabler/icons-react';

interface PricingBadgeProps {
  isFree: boolean;
  pricePaise: number | null;
  ruleName: string | null;
}

export const PricingBadge: React.FC<PricingBadgeProps> = ({ isFree, pricePaise, ruleName }) => {
  if (isFree) {
    return (
      <Badge
        variant="gradient"
        gradient={{ from: 'teal', to: 'green', deg: 135 }}
        size="lg"
        radius="xl"
        leftSection={
          <ThemeIcon size={16} radius="xl" color="green" variant="filled">
            <IconGift size={10} />
          </ThemeIcon>
        }
        styles={{
          root: {
            textTransform: 'none',
            paddingTop: '6px',
            paddingBottom: '6px',
            height: 'auto',
          },
          label: {
            fontWeight: 700,
            fontSize: '14px',
          }
        }}
      >
        🎉 {ruleName || 'Senior'} — Free Entry
      </Badge>
    );
  }

  const price = pricePaise !== null ? (pricePaise / 100).toFixed(0) : '0';

  return (
    <Badge
      variant="gradient"
      gradient={{ from: 'indigo', to: 'violet', deg: 135 }}
      size="lg"
      radius="xl"
      leftSection={
        <ThemeIcon size={16} radius="xl" color="indigo" variant="filled">
          <IconTicket size={10} />
        </ThemeIcon>
      }
      styles={{
        root: {
          textTransform: 'none',
          paddingTop: '6px',
          paddingBottom: '6px',
          height: 'auto',
        },
        label: {
          fontWeight: 700,
          fontSize: '14px',
        }
      }}
    >
      🎟 {ruleName || 'Student Ticket'} — ₹{price}
    </Badge>
  );
};

export default PricingBadge;
