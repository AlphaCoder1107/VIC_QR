<?php

namespace HiEvents\DomainObjects;

class AttendeeCheckInDomainObject extends Generated\AttendeeCheckInDomainObjectAbstract
{
    private ?AttendeeDomainObject $attendee = null;

    private ?CheckInListDomainObject $checkInList = null;

    protected int $scan_slot = 1;
    protected string $slot_label = 'Entry';
    protected int $max_scans = 1;

    public function getScanSlot(): int
    {
        return $this->scan_slot;
    }

    public function setScanSlot(int $scan_slot): self
    {
        $this->scan_slot = $scan_slot;
        return $this;
    }

    public function getSlotLabel(): string
    {
        return $this->slot_label;
    }

    public function setSlotLabel(string $slot_label): self
    {
        $this->slot_label = $slot_label;
        return $this;
    }

    public function getMaxScans(): int
    {
        return $this->max_scans;
    }

    public function setMaxScans(int $max_scans): self
    {
        $this->max_scans = $max_scans;
        return $this;
    }

    public function getAttendee(): ?AttendeeDomainObject
    {
        return $this->attendee;
    }

    public function setAttendee(AttendeeDomainObject $attendee): self
    {
        $this->attendee = $attendee;
        return $this;
    }

    public function setCheckInList(?CheckInListDomainObject $checkInList): AttendeeCheckInDomainObject
    {
        $this->checkInList = $checkInList;
        return $this;
    }

    public function getCheckInList(): ?CheckInListDomainObject
    {
        return $this->checkInList;
    }
}
