<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    private $messagesClient;
    private $notificationManager;

    public function __construct(MessagesClient $messagesClient, NotificationManager $notificationManager)
    {
        $this->messagesClient = $messagesClient;
        $this->notificationManager = $notificationManager;
    }

    /**
     * @throws \Exception
     */
    public function doOperation(array $requestData): array
    {
        $data = $this->validateRequestData($requestData);

        $reseller = $this->getReseller($data['resellerId']);
        $client = $this->getValidatedClient($data, $reseller->id);
        $creator = $this->getEmployee($data['creatorId'], 'Creator not found!');
        $expert = $this->getEmployee($data['expertId'], 'Expert not found!');

        $templateData = $this->prepareTemplateData($data, $client, $creator, $expert);
        $this->validateTemplateData($templateData);

        return $this->sendNotifications($data, $reseller, $client, $templateData);
    }

    private function validateRequestData(array $data): array
    {
        $filteredData = [
            'resellerId' => filter_var($data['resellerId'], FILTER_VALIDATE_INT),
            'notificationType' => filter_var($data['notificationType'], FILTER_VALIDATE_INT),
            'clientId' => filter_var($data['clientId'], FILTER_VALIDATE_INT),
            'creatorId' => filter_var($data['creatorId'], FILTER_VALIDATE_INT),
            'expertId' => filter_var($data['expertId'], FILTER_VALIDATE_INT),
            'complaintId' => filter_var($data['complaintId'], FILTER_VALIDATE_INT),
            'complaintNumber' => filter_var($data['complaintNumber'], FILTER_SANITIZE_STRING),
            'consumptionId' => filter_var($data['consumptionId'], FILTER_VALIDATE_INT),
            'consumptionNumber' => filter_var($data['consumptionNumber'], FILTER_SANITIZE_STRING),
            'agreementNumber' => filter_var($data['agreementNumber'], FILTER_SANITIZE_STRING),
            'date' => filter_var($data['date'], FILTER_SANITIZE_STRING),
            'differences' => $data['differences'] ?? null,
        ];

        foreach ($filteredData as $key => $value) {
            if ($value === false || $value === null) {
                throw new \Exception("Invalid or missing data for key: {$key}", 400);
            }
        }

        return $filteredData;
    }

    private function getReseller(int $resellerId): Seller
    {
        $reseller = Seller::getById($resellerId);
        if (!$reseller) {
            throw new \Exception('Seller not found!', 400);
        }
        return $reseller;
    }

    private function getValidatedClient(array $data, int $resellerId): Contractor
    {
        $client = Contractor::getById($data['clientId']);
        if (!$client || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new \Exception('Client not found or mismatched seller!', 400);
        }
        return $client;
    }

    private function getEmployee(int $employeeId, string $errorMessage): Employee
    {
        $employee = Employee::getById($employeeId);
        if (!$employee) {
            throw new \Exception($errorMessage, 400);
        }
        return $employee;
    }

    private function prepareTemplateData(array $data, Contractor $client, Employee $creator, Employee $expert): array
    {
        $differences = '';
        if ($data['notificationType'] === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $data['resellerId']);
        } elseif ($data['notificationType'] === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO' => Status::getName((int)$data['differences']['to']),
            ], $data['resellerId']);
        }

        return [
            'COMPLAINT_ID' => $data['complaintId'],
            'COMPLAINT_NUMBER' => $data['complaintNumber'],
            'CREATOR_ID' => $data['creatorId'],
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_ID' => $data['expertId'],
            'EXPERT_NAME' => $expert->getFullName(),
            'CLIENT_ID' => $data['clientId'],
            'CLIENT_NAME' => $client->getFullName() ?: $client->name,
            'CONSUMPTION_ID' => $data['consumptionId'],
            'CONSUMPTION_NUMBER' => $data['consumptionNumber'],
            'AGREEMENT_NUMBER' => $data['agreementNumber'],
            'DATE' => $data['date'],
            'DIFFERENCES' => $differences,
        ];
    }

    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $value) {
            if (empty($value)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    private function sendNotifications(array $data, Seller $reseller, Contractor $client, array $templateData): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        $emailFrom = getResellerEmailFrom($reseller->id);
        $emails = getEmailsByPermit($reseller->id, 'tsGoodsReturn');

        if ($emailFrom && !empty($emails)) {
            foreach ($emails as $email) {
                $this->messagesClient->sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $reseller->id),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $reseller->id),
                    ],
                ], $reseller->id, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        if ($data['notificationType'] === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if ($emailFrom && $client->email) {
                $this->messagesClient->sendMessage([
                    0 => [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $client->email,
                        'subject' => __('complaintClientEmailSubject', $templateData, $reseller->id),
                        'message' => __('complaintClientEmailBody', $templateData, $reseller->id),
                    ],
                ], $reseller->id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            if ($client->mobile) {
                $res = $this->notificationManager->send($reseller->id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if ($error) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
