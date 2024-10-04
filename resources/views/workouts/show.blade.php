<x-app-layout>
  <x-slot:header>{{ $workout->name }}</x-slot:header>
  <x-slot:subheading>{{ $workout->description }}</x-slot:subheading>
  <div class="space-y-6">

      <!-- https://www.chartjs.org/ -->
      <script src="https://cdn.jsdelivr.net/npm/chart.js@3.5.1/dist/chart.min.js"></script>

      <div x-data="{
          labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May'],
          values: [200, 150, 350, 225, 125],
          init() {
              let chart = new Chart(this.$refs.canvas.getContext('2d'), {
                  type: 'line',
                  data: {
                      labels: this.labels,
                      datasets: [{
                          data: this.values,
                          backgroundColor: '#77C1D2',
                          borderColor: '#77C1D2',
                      }],
                  },
                  options: {
                      interaction: { intersect: false },
                      scales: { y: { beginAtZero: true } },
                      plugins: {
                          legend: { display: false },
                          tooltip: {
                              displayColors: false,
                              callbacks: {
                                  label(point) {
                                      return 'Sales: $' + point.raw
                                  }
                              }
                          }
                      }
                  }
              })
      
              this.$watch('values', () => {
                  chart.data.labels = this.labels
                  chart.data.datasets[0].data = this.values
                  chart.update()
              })
          }
      }" class="w-1/2">
        <canvas x-ref="canvas" class="rounded-lg bg-white p-2"></canvas>
      </div>
    <div class="flex justify-end">
      <flux:button icon="pencil-square" href="{{ route('workouts.edit', $workout) }}">{{ __('Edit') }}</flux:button>
    </div>
    <flux:table>
      <flux:columns>
        <flux:column>{{ __('Exercise') }}</flux:column>
        <flux:column>{{ __('Sets') }}</flux:column>
        <flux:column></flux:column>
      </flux:columns>

      <flux:rows>
        @foreach ($exercises as $exercise)
          <livewire:workouts.workout-row :$exercise :$workout :key="$exercise->id" />
        @endforeach
      </flux:rows>
    </flux:table>
  </div>
</x-app-layout>
