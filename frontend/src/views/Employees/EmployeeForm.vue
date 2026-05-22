<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/api/client'
import { useToast } from 'vue-toastification'
import SearchableSelect from '@/components/SearchableSelect.vue'

const router = useRouter()
const route = useRoute()
const toast = useToast()
const isEdit = computed(() => !! route.params.id)
const loading = ref(false)
const saving = ref(false)

const form = ref({
  employee_number: '',
  first_name: '',
  last_name: '',
  gender: '',
  marital_status: 'TK0',
  ptkp_status: 'TK0',
  email: '',
  phone: '',
  date_of_birth: '',
  place_of_birth: '',
  address: '',
  province: '',
  city: '',
  postal_code: '',
  nik: '',
  npwp: '',
  bpjs_kesehatan_no: '',
  bpjs_tk_no: '',
  bank_name: '',
  bank_account: '',
  bank_account_holder: '',
  branch_id: null,
  department_id: null,
  position_id: null,
  job_grade_id: null,
  manager_id: null,
  employment_status: 'PKWTT',
  employment_type: 'FULL_TIME',
  join_date: new Date().toISOString().slice(0, 10),
  probation_end_date: '',
  contract_end_date: '',
  basic_salary: 0,
  tax_method: 'GROSS',
  pph21_method: 'TER',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  emergency_contact_rel: '',
  create_user_account: true,
})

const departments = ref([])
const positions = ref([])
const branches = ref([])
const jobGrades = ref([])

onMounted(async () => {
  loading.value = true
  try {
    const [d, p, b, j] = await Promise.all([
      api.get('/departments', { params: { per_page: 200 } }),
      api.get('/positions', { params: { per_page: 200 } }),
      api.get('/branches', { params: { per_page: 200 } }),
      api.get('/job-grades', { params: { per_page: 200 } }),
    ])
    departments.value = d.data.data
    positions.value = p.data.data
    branches.value = b.data.data
    jobGrades.value = j.data.data

    if (isEdit.value) {
      const res = await api.get(`/employees/${route.params.id}`)
      form.value = { ...form.value, ...res.data.data }
    }
  } finally {
    loading.value = false
  }
})

async function submit() {
  saving.value = true
  try {
    if (isEdit.value) {
      await api.put(`/employees/${route.params.id}`, form.value)
      toast.success('Karyawan diperbarui')
    } else {
      await api.post('/employees', form.value)
      toast.success('Karyawan ditambahkan')
    }
    router.push('/employees')
  } catch (e) {
    const errors = e.response?.data?.errors
    if (errors) {
      toast.error(Object.values(errors).join('; '))
    } else {
      toast.error(e.response?.data?.message || 'Gagal menyimpan')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">{{ isEdit ? 'Edit Karyawan' : 'Karyawan Baru' }}</h1>

    <form v-if="!loading" @submit.prevent="submit" class="space-y-6">
      <!-- Identity -->
      <div class="card">
        <div class="card-header"><span class="font-semibold">Identitas</span></div>
        <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label">NIP *</label>
            <input v-model="form.employee_number" class="input" required :disabled="isEdit" />
          </div>
          <div></div>
          <div>
            <label class="label">Nama Depan *</label>
            <input v-model="form.first_name" class="input" required />
          </div>
          <div>
            <label class="label">Nama Belakang</label>
            <input v-model="form.last_name" class="input" />
          </div>
          <div>
            <label class="label">Email</label>
            <input v-model="form.email" type="email" class="input" />
          </div>
          <div>
            <label class="label">Telepon</label>
            <input v-model="form.phone" class="input" />
          </div>
          <div>
            <label class="label">Jenis Kelamin</label>
            <select v-model="form.gender" class="input">
              <option value="">- Pilih -</option>
              <option value="M">Laki-laki</option>
              <option value="F">Perempuan</option>
            </select>
          </div>
          <div>
            <label class="label">Tempat Lahir</label>
            <input v-model="form.place_of_birth" class="input" />
          </div>
          <div>
            <label class="label">Tanggal Lahir</label>
            <input v-model="form.date_of_birth" type="date" class="input" />
          </div>
          <div>
            <label class="label">NIK KTP (terenkripsi)</label>
            <input v-model="form.nik" class="input" maxlength="16" />
          </div>
          <div>
            <label class="label">NPWP</label>
            <input v-model="form.npwp" class="input" />
          </div>
          <div>
            <label class="label">Status PTKP</label>
            <select v-model="form.ptkp_status" class="input">
              <option v-for="c in ['TK0','TK1','TK2','TK3','K0','K1','K2','K3','HB0','HB1','HB2','HB3']" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Address -->
      <div class="card">
        <div class="card-header"><span class="font-semibold">Alamat</span></div>
        <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="label">Alamat</label>
            <textarea v-model="form.address" class="input" rows="2"></textarea>
          </div>
          <div><label class="label">Provinsi</label><input v-model="form.province" class="input" /></div>
          <div><label class="label">Kota</label><input v-model="form.city" class="input" /></div>
          <div><label class="label">Kode Pos</label><input v-model="form.postal_code" class="input" /></div>
        </div>
      </div>

      <!-- Employment -->
      <div class="card">
        <div class="card-header"><span class="font-semibold">Kepegawaian</span></div>
        <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label">Cabang</label>
            <SearchableSelect
              v-model="form.branch_id"
              :items="branches"
              value-key="id"
              label-key="name"
              :search-keys="['name','code','city']"
              placeholder="- Pilih -"
            />
          </div>
          <div>
            <label class="label">Departemen</label>
            <SearchableSelect
              v-model="form.department_id"
              :items="departments"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="- Pilih -"
            />
          </div>
          <div>
            <label class="label">Jabatan</label>
            <SearchableSelect
              v-model="form.position_id"
              :items="positions"
              value-key="id"
              label-key="name"
              sub-label-key="department_name"
              :search-keys="['name','code','tier','department_name']"
              placeholder="- Pilih -"
            />
          </div>
          <div>
            <label class="label">Job Grade</label>
            <SearchableSelect
              v-model="form.job_grade_id"
              :items="jobGrades"
              value-key="id"
              label-key="name"
              :search-keys="['name','code']"
              placeholder="- Pilih -"
            />
          </div>
          <div>
            <label class="label">Status Kepegawaian *</label>
            <select v-model="form.employment_status" class="input">
              <option v-for="s in ['PKWT','PKWTT','PROBATION','HARIAN','MAGANG','OUTSOURCING']" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div>
            <label class="label">Tipe Pekerja</label>
            <select v-model="form.employment_type" class="input">
              <option v-for="s in ['FULL_TIME','PART_TIME','CONTRACT','INTERN']" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div>
            <label class="label">Tanggal Bergabung *</label>
            <input v-model="form.join_date" type="date" class="input" required />
          </div>
          <div>
            <label class="label">Akhir Probation</label>
            <input v-model="form.probation_end_date" type="date" class="input" />
          </div>
          <div>
            <label class="label">Akhir Kontrak</label>
            <input v-model="form.contract_end_date" type="date" class="input" />
          </div>
        </div>
      </div>

      <!-- Salary -->
      <div class="card">
        <div class="card-header"><span class="font-semibold">Gaji & Pajak</span></div>
        <div class="card-body grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="label">Gaji Pokok</label>
            <input v-model.number="form.basic_salary" type="number" min="0" class="input" />
          </div>
          <div>
            <label class="label">Metode Pajak</label>
            <select v-model="form.tax_method" class="input">
              <option value="GROSS">Gross</option>
              <option value="NETT">Nett</option>
              <option value="GROSS_UP">Gross Up</option>
            </select>
          </div>
          <div>
            <label class="label">Bank</label>
            <input v-model="form.bank_name" class="input" />
          </div>
          <div>
            <label class="label">No. Rekening (terenkripsi)</label>
            <input v-model="form.bank_account" class="input" />
          </div>
          <div>
            <label class="label">Pemilik Rekening</label>
            <input v-model="form.bank_account_holder" class="input" />
          </div>
          <div>
            <label class="label">No. BPJS Kesehatan</label>
            <input v-model="form.bpjs_kesehatan_no" class="input" />
          </div>
          <div>
            <label class="label">No. BPJS Ketenagakerjaan</label>
            <input v-model="form.bpjs_tk_no" class="input" />
          </div>
        </div>
      </div>

      <!-- Account -->
      <div v-if="!isEdit" class="card">
        <div class="card-header"><span class="font-semibold">Akun Aplikasi</span></div>
        <div class="card-body">
          <label class="flex items-center gap-2">
            <input type="checkbox" v-model="form.create_user_account" />
            <span class="text-sm">Buat akun login otomatis (gunakan email di atas; password sementara dikirim via email)</span>
          </label>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="$router.back()">Batal</button>
        <button type="submit" class="btn-primary" :disabled="saving">
          {{ saving ? 'Menyimpan...' : (isEdit ? 'Simpan Perubahan' : 'Simpan') }}
        </button>
      </div>
    </form>
    <div v-else class="text-center py-12 text-gray-400">Memuat...</div>
  </div>
</template>