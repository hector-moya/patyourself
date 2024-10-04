<div>
  <!-- https://www.chartjs.org/ -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@3.5.1/dist/chart.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

  <div x-data="{
      init() {
          chartData = $wire.chartData;
  
          console.log(chartData);
          let chart = new Chart(this.$refs.canvas.getContext('2d'), {
              type: 'line',
              data: { datasets: chartData },
              options: {
                  scales: {
                      x: {
                          type: 'time',
                          time: {
                              unit: 'day',
                              tooltipFormat: 'MMM dd HH:mm',
                          },
                      },
                      y: {
                          beginAtZero: true,
                          title: {
                              display: true,
                              text: 'Weight'
                          }
                      }
                  },
                  plugins: {
                      legend: {
                          display: false
                      },
                      tooltip: {
                          displayColors: false
                      }
                  }
              }
          })
  
          this.$watch('chartData', () => {
              chart.data.datasets = chartData;
              chart.update();
          })
      }
  }" class="w-1/2">
    <canvas x-ref="canvas" class="rounded-lg bg-white p-2"></canvas>
  </div>
</div>
