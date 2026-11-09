<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\Peminjaman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarangControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_access_another_users_barang(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $barang = $this->createBarang($owner);

        $this->actingAs($user)
            ->get(route('barang.show', $barang))
            ->assertNotFound();

        $this->get(route('barang.edit', $barang))
            ->assertNotFound();

        $this->put(route('barang.update', $barang), $this->barangData())
            ->assertNotFound();

        $this->delete(route('barang.destroy', $barang))
            ->assertNotFound();

        $this->assertDatabaseHas('barangs', [
            'id' => $barang->id,
            'user_id' => $owner->id,
            'nama_barang' => 'Laptop',
        ]);
    }

    public function test_update_ignores_user_id_from_request_payload(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $barang = $this->createBarang($user);

        $response = $this->actingAs($user)
            ->put(route('barang.update', $barang), [
                ...$this->barangData(),
                'nama_barang' => 'Laptop Baru',
                'user_id' => $otherUser->id,
            ]);

        $response->assertRedirect(route('barang.index'));
        $this->assertDatabaseHas('barangs', [
            'id' => $barang->id,
            'user_id' => $user->id,
            'nama_barang' => 'Laptop Baru',
        ]);
    }

    public function test_barang_with_active_borrowing_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $barang = $this->createBarang($user);
        Peminjaman::create([
            'barang_id' => $barang->id,
            'nama_peminjam' => 'Test Borrower',
            'jumlah_pinjam' => 1,
            'tanggal_pinjam' => '2026-10-25',
            'tanggal_kembali' => null,
            'status' => 'Dipinjam',
        ]);

        $response = $this->actingAs($user)
            ->delete(route('barang.destroy', $barang));

        $response->assertRedirect(route('barang.index'))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('barangs', ['id' => $barang->id]);
    }

    private function createBarang(User $user): Barang
    {
        return Barang::create([
            'user_id' => $user->id,
            ...$this->barangData(),
        ]);
    }

    /** @return array<string, int|string> */
    private function barangData(): array
    {
        return [
            'kode_barang' => 'BRG-TEST',
            'nama_barang' => 'Laptop',
            'kategori' => 'Elektronik',
            'stok' => 5,
            'satuan' => 'unit',
            'harga_satuan' => 1000000,
            'kondisi' => 'Baik',
        ];
    }
}
