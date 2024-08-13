@if ('guest')
    <x-guest-layout>
        <div class="bg-gray-900">
            <main>
                <!-- Hero section -->
                <div class="relative isolate overflow-hidden">
                    <div class="absolute left-[calc(50%-4rem)] top-10 -z-10 transform-gpu blur-3xl sm:left-[calc(50%-18rem)] lg:left-48 lg:top-[calc(50%-30rem)] xl:left-[calc(50%-24rem)]"
                        aria-hidden="true">
                    
                    </div>
                    <div class="mx-auto max-w-7xl px-6 pb-24 pt-10 sm:pb-40 lg:flex lg:px-8 lg:pt-40">
                        <div class="mx-auto max-w-2xl flex-shrink-0 lg:mx-0 lg:max-w-xl lg:pt-8">
                            <x-sections.logo />
                            <div class="mt-24 sm:mt-32 lg:mt-16">
                                <a href="#" class="inline-flex space-x-6">
                                    <span class="rounded-full bg-indigo-500/10 px-3 py-1 text-sm font-semibold leading-6 text-indigo-400 ring-1 ring-inset ring-indigo-500/20">
                                        Latest updates
                                    </span>
                                </a>
                            </div>
                            <h1 class="mt-10 text-4xl font-bold tracking-tight text-white sm:text-6xl">
                                Progress, Not Perfection
                            </h1>
                            <p class="mt-6 text-lg leading-8 text-gray-300">
                                Perfection is a myth. Progress is real. PatYourself helps you build lasting habits, one small step at a time
                            </p>
                            <div class="mt-10 flex items-center gap-x-6">
                                <a href="{{ route('register')}}" class="rounded-md bg-indigo-500 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-400">
                                    Create Account
                                </a>
                                <a href="{{ route('login')}}" class="rounded-md bg-indigo-500 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-400">
                                    Login
                                </a>
                            </div>
                        </div>
                        <div class="mx-auto mt-16 flex max-w-2xl sm:mt-24 lg:ml-10 lg:mr-0 lg:mt-0 lg:max-w-none lg:flex-none xl:ml-32">
                            <div class="max-w-3xl flex-none sm:max-w-5xl lg:max-w-none">
                                <img src="{{ asset('images/mascot.webp')}}" alt="App screenshot" width="2432" height="1442"
                                    class="w-[48rem] rounded-md bg-white/5 shadow-2xl ring-1 ring-white/10">
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <x-sections.footer />
        </div>
    </x-guest-layout>
@endif
