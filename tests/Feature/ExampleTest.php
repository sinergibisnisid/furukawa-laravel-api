<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_upload_accepts_the_template_xlsx(): void
    {
        $group = UserGroup::create(['name' => 'Administrator']);
        $user = User::create([
            'username' => 'admin',
            'email' => 'admin@furukawa.local',
            'password' => '',
            'password_hash' => bcrypt('admin12345'),
            'password_migrated_at' => now(),
            'must_change_password' => false,
            'user_group_id' => $group->id,
        ]);

        $uploadedFile = $this->buildEmptyIncomingTemplateUpload();

        try {
            Sanctum::actingAs($user);

            $response = $this->post('/api/incomings-detail/upload/material', [
                'file' => $uploadedFile,
            ]);
        } finally {
            @unlink($uploadedFile->getRealPath());
        }

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Upload processed',
            ])
            ->assertJsonCount(0, 'data.errors')
            ->assertJsonCount(0, 'data.duplicates');

        $this->assertDatabaseCount('incomings', 0);
        $this->assertDatabaseCount('incomings_details', 0);
        $this->assertDatabaseCount('material_movements', 0);
        $this->assertDatabaseHas('activity_logs', [
            'user_email' => 'admin@furukawa.local',
            'activity_type' => ActivityLog::TYPE_UPLOAD,
            'activity_name' => 'Incoming',
            'activity_description' => 'Upload file: format-upload-data-incoming.xlsx',
        ]);
    }

    private function buildEmptyIncomingTemplateUpload(): UploadedFile
    {
        $templatePath = storage_path('app/templates/format-upload-data-incoming.xlsx');
        $book = IOFactory::load($templatePath);

        foreach ([0, 1] as $sheetIndex) {
            $sheet = $book->getSheet($sheetIndex);
            $rowsToRemove = max(0, $sheet->getHighestRow() - 2);

            if ($rowsToRemove > 0) {
                $sheet->removeRow(3, $rowsToRemove);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'incoming-upload-');
        self::assertNotFalse($tempPath);

        $xlsxPath = $tempPath.'.xlsx';
        @unlink($tempPath);

        (new Xlsx($book))->save($xlsxPath);

        return new UploadedFile(
            $xlsxPath,
            'format-upload-data-incoming.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
